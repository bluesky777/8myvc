# Lote R — El boletín de una familia (§140–142, §166 y §167)

> Sesión `8myvc-06`, árbol `.worktrees/r`, rama `fix/lote-r-boletin-de-la-familia`,
> base `simonbolivar_testing_r`. Noche del 22 al 23 de agosto de 2026.
>
> **Es el único hallazgo de la noche que le está pasando a un colegio ahora
> mismo**: una familia entra, pide el boletín y recibe un error del servidor.

Dos fallos distintos en los mismos dos ficheros, y los dos tapados por la misma
cosa: **la maqueta 1 se prueba y las otras dos no**. Y un tercero, la §167, que
no venía en el lote: salió de una anotación propia del lote J que quedó escrita
como *candidato no medido*.

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

## §166 — La quinta copia que hubo que revertir, y la sospecha que era falsa

`encabezado_comportamiento_boletin()` está copiada en **cinco** controladores de
`Informes/`, así que se cambiaron las cinco. **Larastan paró la quinta**:
`binaryOp.invalid` en `BolfinalesPreescolarController:276`.

Al medir por qué: **esa copia no recibe lo mismo que las otras cuatro.** Su único
llamante (línea 209) le pasa `$alumno->nota_comportamiento_year`, que sale de
`NotaComportamiento::nota_promedio_year()` y es **siempre un `(int)`** — un
promedio, o `0` si no hay notas. Nunca es el objeto ni el centinela.

Con un número, `is_object` es **siempre falso**: el cambio habría apagado la
cabecera de comportamiento del boletín de preescolar **en silencio y con los
tests en verde**. Revertida.

> **Ampliar un arreglo a «todas las copias» sin comprobar qué recibe cada una es
> la forma de romper la que estaba bien.**

### Y la sospecha que salió de ahí era falsa, que es la mitad que importa

Al revertir se anotó esto como pendiente para otro lote:

> esa quinta copia hace `$la_nota = $nota;` donde las otras cuatro hacen
> `$la_nota = $nota->nota;`, y ese valor se concatena en el HTML. Es la única de
> las cinco que difiere.

**Era falso, y se descubrió al medir de dónde viene el valor.** Con `$nota`
siendo ya la nota —un entero—, `$la_nota = $nota;` es exactamente lo correcto:
en las otras cuatro `$nota` es la **fila** y hay que sacarle `->nota`; aquí ya
es la nota. La asimetría es real y **no es un fallo**.

Y el aviso de larastan que la levantó lo estaba provocando **el `is_object` de
más**, no el código de preescolar. O sea:

> Un aviso del analizador que aparece **junto con tu cambio** describe tu cambio
> hasta que se demuestre lo contrario. Se anotó como «hay un fallo latente ahí»
> y lo que había era el arreglo mal ampliado.

Se corrige aquí y no se deja anotado: **una pista falsa en la lista de otro lote
cuesta más que no escribir nada**, porque llega con la autoridad de venir medida.

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

**Se fijó y no se arregló**, con esta justificación: *«el bucle interior sólo
procesa a los alumnos que aparecen en la lista, así que un `[]` por defecto
devolvería 200 con el grupo vacío —un 200 hueco— y la otra salida es 422. Cuál de
las dos es una decisión.»*

### Y esa justificación era falsa — resuelto el 24 ago 2026

**Había una tercera salida, y no había que inventarla: ya era el comportamiento
del proyecto.** `detailedNotasGrupo` está copiado en **nueve** controladores —los
tres boletines, los dos bolfinales, preescolar, certificados, `editnota` y éste—
y **ocho llevan `if ($requested_alumnos == '')` delante del bucle, metiendo a todo
el grupo**. Sólo a esta copia se le cayó esa línea.

Así que no era «200 hueco contra 422»: era **«sin lista, entran todos»**, escrito
ocho veces al lado y ya esperado por las pantallas equivalentes. Repuesta la
guarda, el centinela pasa a vigilar que **sigan saliendo todos los matriculados**
— porque un 200 con menos alumnos de los que hay sí sería el 200 hueco, y ése no
se distingue del bueno mirando el código.

> **La lección es del centinela, no del fallo.** Al fijar un comportamiento roto
> se enumeraron las salidas **pensándolas**, en vez de mirar qué hacían las ocho
> copias hermanas. Un centinela que enumera opciones puede dejar fuera la
> correcta, y entonces **la decisión que reclama es una decisión falsa** — y se
> queda esperando a alguien que no tenía nada que decidir.
>
> Lo destapó `myvc-front-12` barriendo 107 rutas en Chrome con el log del backend
> delante: no lo encontró releyendo esto, sino pidiendo el endpoint.

### Y el detector que buscó el síntoma y contó la causa

Al arreglarlo se barrió el patrón por si había más: *«parámetro con defecto
escalar que se recorre con `foreach`»*. **Dio nueve.** Y nueve era **verdad** —
las nueve copias recorren ese parámetro—. Lo que no era verdad es lo que se leyó
en ese nueve: *«nueve sitios sin guarda»*. Ocho la tienen, y el `foreach` que el
detector encontraba en ellas es el de dentro del `else`.

Estuvo a punto de costar ocho commits arreglando lo que no estaba roto.

**Y es un fallo distinto del de medir poco**, aunque se parezcan:

| | Qué falló | Cómo se arregla |
|---|---|---|
| Medir **una** pasada y sacar un 3× que era la caché ([02](../02-plan-rendimiento.md)) | la **cantidad** de medición | repetirla, alternando el orden |
| Deducir «0 fallan» de haber arreglado el único rojo | la medición **no se hizo** | hacerla |
| **Contar `foreach` y leer «sin guarda»** | la medición fue **correcta y contestó otra pregunta** | **comprobar que el detector detecta lo que dice su nombre** |

Los dos primeros se arreglan repitiendo. **El tercero no**: repetirlo da nueve
otra vez, y otra vez parecerá que hay nueve sitios rotos. Un número honesto puede
sostener una conclusión falsa sin que nada en el número lo delate.

> **Las dos formas de esta página, juntas, porque son la misma con distinto
> traje:**
>
> - **Un centinela que enumera opciones puede dejar fuera la correcta.**
> - **Un detector que cuenta síntomas puede no estar contando la causa.**
>
> Y en los dos casos la salida fue la misma y era barata: **mirar a las
> hermanas.** La guarda estaba escrita ocho veces al lado, tanto para saber qué
> debía contestar el endpoint como para saber cuántos sitios estaban rotos. Es lo
> que dice la §16 sobre la ruta que se queda sola de su familia — **la familia es
> la fuente de la verdad, y consultarla es más barato que razonar.**

---

---

## §167 — Colgar en el muro del colegio la imagen privada de otro

No venía en el lote: venía de **una anotación propia del lote J** que decía,
literal, *«no afirmo que filtre: afirmo que no lo he mirado»*. Salió del barrido
de rutas abiertas que ningún candado mira y ningún test juzga, y quedó como
candidato porque medirla necesitaba la base y había seis `phpunit` vivos.

Medida en cuanto hubo hueco, **y filtra**.

### De punta a punta

1. un alumno manda `imagen: {id: 5, nombre: "imagen-5.jpg"}` — esa imagen tiene
   `publica IS NULL` y `user_id = 2`, o sea que es **de otra persona**;
2. la fila entra tal cual: `imagen_id=5, imagen_nombre="imagen-5.jpg"`;
3. y `publicaciones/ultimas` **le sirve ese nombre a todo el mundo**, comprobado
   con el token de un profesor que no es el dueño.

**La imagen privada de cualquiera acaba publicada en el muro del colegio sólo con
nombrar su id.** Es la familia de la [§53](../05-codigo-muerto-y-roto.md) —donde
`images-users/imagenes-de-usuario` soltaba 162 imágenes privadas— con una
diferencia que la empeora: **allí se listaban, aquí se publican.**

### La regla no se inventa aquí

**Tuya, o pública.** Es exactamente lo que ya decide la pantalla que elige la
imagen: `ImagesController::getIndex()` devuelve las privadas del que pregunta y,
sólo a superusuario o profesor, las `publica = 1`. La comprobación no le quita
ninguna opción a ningún cliente que use el selector — le quita las que el
selector nunca le ofreció.

No se reutiliza el guard `persona.propia:imagen_id`: el cuerpo trae `imagen.id`
**anidado** y no `imagen_id`, así que el middleware no lo encontraría, y cambiar
la forma del cuerpo es tocar el contrato de cuatro clientes por una comprobación
que aquí cuesta cinco líneas.

### Va en los dos métodos, y el segundo parecía cubierto

`putGuardarEdicion` **ya llamaba** a `exigeQueLaPublicacionSeaSuya()`. Lo que no
comprobaba es **de quién es la imagen que le pones**.

> Una comprobación puesta no dice que estén puestas las que faltan.

**Comprobado al revés, dos veces:**

| Qué se probó | Qué cae |
|---|---|
| Revertido del todo | 2 de 3 |
| **Sólo `putStore`**, dejando el hermano sin tocar | pasa el alta y cae **sólo la edición** |

Y la tercera mitad, contra el 403 de más: colgar la imagen **propia** sigue
llegando al muro, leído con otro usuario.

`ImagenAjenaEnElMuroTest` · commit `0e576ec`

> Lo que hace que esto exista es que el candidato se escribió **como candidato** y
> no como hallazgo ni como silencio. Un «no lo he mirado» con nombre y ruta se
> puede volver a coger; un hueco sin anotar, no.

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

- ~~**§142**: qué debe contestar `notas-actuales-alumnos` sin `requested_alumnos`
  —200 con el grupo vacío o 422—. Hoy es 500.~~ **Resuelto el 24 ago 2026, y no
  hacía falta decidir nada**: ocho de las nueve copias del método ya devolvían el
  grupo entero. Ver la §142.

### Para otros lotes

- **§166 se retira de esta lista**: se anotó como sospecha y **se midió después
  que era falsa** — ver arriba. No hay nada que mirar en
  `BolfinalesPreescolarController`.
- **`PuestosController:271`** escribe a mano `count($comportamiento) > 0 ?
  $comportamiento[0] : []`: **la misma dualidad en un séptimo sitio**, o sea que
  no es «un modelo con dos formas» sino un modismo del proyecto. No es de este
  lote y no se tocó.

### Nada de esto

- Ninguna migración.
- El modelo `NotaComportamiento` **sin tocar**: su centinela es contrato con
  cuatro plantillas.
