# Lote N — Los ayudantes compartidos de los tests

> Sesión `8myvc-ec`, noche del 22 al 23 de agosto de 2026. Rama
> `fix/lote-n-ayudantes-de-test`, árbol `.worktrees/n`, base `simonbolivar_testing_n`.
>
> Secciones **§157–159**. Va el último a propósito: toca
> `tests/Contrato/CasoDeContrato.php`, que heredan los 130 ficheros de contrato.

Sale del [lote I](i.md): al arreglar el sujeto del barrido se vio que el ayudante que
usa **todo el resto de la suite** tiene el mismo problema, y peor, porque nadie lo mira.

---

## §157 · Lo que devuelve el ayudante no es lo que su nombre promete

```
usuarioDeTipo('Usuario')   ->  usuario 1     is_superuser = 1, rol Admin
tokenDelPersonalDe(8)      ->  usuario 1     is_superuser = 1
tokenDelPersonalDe(7)      ->  usuario 685   is_superuser = 0
```

De los **veinte `Usuario` activos del seed, diez son superusuario**, y son los de id más
bajo. Los dos ayudantes ordenan por id, así que el primero siempre lo es.

**Y `tokenDelPersonalDe()` devuelve una cosa u otra según el año.** Ninguna llamada pasa
el año literal: todas pasan `$grupo->year_id`, así que **el sujeto depende del grupo que
ese test eligió y no se puede ver leyendo el test**.

### El ayudante no estaba mal: estaba bien para otra pregunta

`usuarioDeTipo()` se escribió para los tests de contrato, donde lo que se mira es **la
forma de la respuesta**, y ahí «cualquiera del tipo» es exactamente lo correcto — su
propio docblock explica con cuidado por qué no vale cualquiera *dentro* del tipo. Lo que
falla es reutilizarlo para **«¿qué puede hacer alguien del personal?»**, donde
«cualquiera» es lo único que no vale.

Es la misma frase del [§111](i.md), y aquí decide el arreglo: **se añade un ayudante, no
se cambia el viejo.**

---

## §158 · Qué se cambia y qué no, y por qué el reparto no es el que parece

Clasificados los métodos que usan uno de los dos ayudantes, **por lo que afirman con ese
sujeto**:

| Lo que afirma | Cuántos | Qué se hace |
|---|---|---|
| Sólo un **permiso** (200/201/204) | **41** | **repuntar**: el superusuario prueba **menos** de lo que dice el nombre |
| Un **rechazo** (401/403/404/422/429) o un **500** | 39 | **dejar**: prueban **más**, no menos |
| De ésos, con un nombre que promete algo del sujeto | **2** | **renombrar** |
| Uno mezclado, con un «el personal puede» dentro | 1 | **repuntar** esa mitad |
| La única instantánea | 1 | **no tocar** |

**El trabajo es repuntar 42 y renombrar 2.** No son 87 ni 301, y tampoco «31 renombres»:
al mirar los 39 uno por uno, **sólo dos tienen un nombre que afirme algo sobre quién es
el sujeto**. Los otros 37 se llaman «no se puede X» y son ciertos con cualquiera.

### Por qué los 39 se dejan, que es lo que sostiene «añadir y no modificar»

Pasan hoy **porque el sujeto es un superusuario**, y algunos pasan *por eso*:

```php
// PedidosDeAsignaturaTest, antes
public function test_un_administrativo_recibe_403_al_pedir_una_materia()
```

Ese test es **verdad y su nombre es falso**: el sujeto es el superusuario, y pasa porque
esa ruta rechaza **incluso** a un superusuario, que es más fuerte que lo que promete.
**Repuntarlo lo debilitaría.** Por eso se renombra y no se toca:
`test_ni_un_superusuario_puede_pedir_una_materia`.

Si se le hubiera cambiado el criterio al ayudante viejo en vez de añadir uno,
esos 39 habrían cambiado de significado **sin que nadie lo pidiera**.

### Los dos ayudantes nuevos

`usuarioLlanoDelPersonal()` / `tokenDelPersonalLlano()` exigen `is_superuser = 0`.

Y hace falta un segundo, `tokenDelPersonalLlanoDe($yearId)`, porque nueve de los 41 no
llaman al primero sino a `tokenDelPersonalDe($grupo->year_id)`: **el año no se puede
perder**, o el informe sale vacío en 200 y el test deja de comprobar nada — que es
exactamente lo que su docblock lleva avisando desde que se escribió. Comprobado antes de
escribirlo: el seed tiene **al menos dos `Usuario` llanos en cada uno de los tres años**
con usuarios (679 en el 1, 685 en el 7, 684 en el 8).

Los dos **fallan con nombre** si el seed dejara de traer uno, en vez de devolver un
superusuario y llamarlo personal:

> **Un ayudante que dice a quién eligió no puede elegir a otro en silencio** — que es
> literalmente el fallo que este lote viene a cerrar.

---

## §159 · Dos cosas que salieron haciéndolo, y las dos son del propio lote

### 1. La herramienta del arreglo cometió el fallo que el arreglo corrige

El primer intento fue un `replace` sobre `SuperficieDeUnAlumnoTest`. Tocó **cinco**
donde debían ser **tres**, y los dos que sobraban afirmaban **un 403 y un 500**: justo
los que prueban de más y hay que dejar quietos.

> **La población del cambio son métodos con nombre, no «las apariciones en estos
> ficheros».**

Se revirtieron los dos y se escribió una herramienta que sustituye **sólo dentro de los
métodos que se le nombran**. Y su contador también mintió por el camino —sumaba dos
patrones donde uno es subcadena del otro, y decía cuatro donde había dos—, así que ahora
cuenta lo que queda después y no lo que casaba antes.

### 2. La espera no fue gratis: 35 → 41

La lista se midió primero en `.worktrees/i`, **antes de fundir nueve lotes**, y daba
**35**. Sobre `main` completo son **41**, y los seis nuevos son **tests escritos esta
misma noche por los otros lotes** usando el ayudante viejo: `BitacorasTest` (5),
`CentinelaDelComportamientoTest` (2), `EditarUnaEscalaDeValoracionTest` (3),
`ColumnasQueSoloViajan`, `DosGetQueCreanPiar`, `EditarUnCatalogo`,
`BorrarUnCatalogoQueNoExiste`.

Aplazar N fue lo correcto —tocar el suelo con tres sesiones trabajando encima era peor—
pero **no fue gratis**, y las dos mitades tienen que quedar juntas:

> Un número sin su commit al lado **describe un momento**. Y mientras el arreglo espera,
> **el problema sigue creciendo al ritmo al que se escriben tests**.

---

## La predicción, escrita antes de correr

Antes de la corrida se cruzaron los 42 repuntados con las **43 rutas que en el
[barrido del lote I](i.md) sólo alcanzó el superusuario**, para escribir **qué rojos
debería dar N**. Va aquí porque **una predicción publicada después del resultado no es
una predicción**.

```
ColumnasQueSoloViajanTest::test_la_ficha_medica_trae_las_columnas_que_el_backend_no_nombra   api/enfermeria/datos
EditnotaBorraAlumnosTest::test_un_periodos_a_calcular_desconocido_devuelve_vacio_en_200      api/editnota/alum-asignatura
```

Con sus dos cegueras delante del número:

1. **Sólo ve rutas escritas como literal** en el test; las armadas con variables se le
   escapan. **Dos es un suelo, no un techo.**
2. **«Sólo la alcanzó el superusuario» en el barrido puede venir del identificador ajeno
   con el que se golpeó, no del rol** — `enfermeria/datos` lleva `persona.propia`.

Y lo que hace que valga la pena escribirla es que **las tres salidas enseñan algo
distinto**:

| Si la corrida da… | Lo que hemos aprendido |
|---|---|
| esos dos | el cruce barrido ↔ suite funciona: hay forma barata de predecir el alcance de un repunte **sin correrlo** |
| otros | el cruce tiene un agujero **con nombre**: las rutas armadas con variables |
| ninguno | los dos candidatos eran del identificador y no del rol, o sea que **la lista de «solo superusuario» del barrido mezcla dos causas** — la ceguera que el lote I dejó anotada y no pudo separar |

---

## §159.3 · Lo que dio la corrida: tres rojos, y dos son del propio arreglo

`1.165 pasados, 3 fallidos`. Los tres se miraron uno a uno, porque **dejarlos en el
mismo saco era lo único que no valía**.

### 1. `ExcelTest` — el arreglo cambió el año en silencio, y es el hallazgo del lote

```
RuntimeException: La hoja '4' no corresponde a ningún grupo del año 2018
```

`usuarioLlanoDelPersonal()` devolvía el `Usuario` llano **de id más bajo**, que es el
679 — y su periodo es de **2018**. El que devolvía `usuarioDeTipo('Usuario')` era el 1,
de **2025**, que es el año actual.

**O sea que el repunte arregló quién era el sujeto y de paso mudó cuarenta y un tests
siete años atrás.** Y **sólo uno se quejó**: el que importa una hoja del año del usuario.
Los otros cuarenta no miran el año — que **no es lo mismo que seguir midiendo lo mismo**.

Es exactamente el fallo del que avisa `tokenDelPersonalDe()` desde que se escribió: con
un sujeto de otro año los listados salen vacíos **en 200** y el test pasa sin haber
calculado nada. **Cambiar un sujeto silencioso por otro no es arreglarlo.**

Arreglado anclando el ayudante a `years.actual = 1`. **Y comprobado que el arreglo
restaura la paridad en la dimensión que rompió**, que es lo que faltaba para poder decir
que está arreglado y no sólo cambiado:

```
el ayudante anclado devuelve:  user 684  is_superuser = 0   año 2025
el viejo devolvía:             user 1    is_superuser = 1   año 2025
```

**Mismo año.** Así lo único que cambia para los 38 repuntados es `is_superuser`, que es
exactamente el cambio que se buscaba — y ninguna otra cosa.

### 2. `PapeleraTest` — el test fija el sujeto en una aserción

```php
$usuario = $this->usuarioLlanoDelPersonal();
$this->assertSame(1, (int) $usuario->is_superuser);   // <- su premisa
```

El borrado físico es sólo de superusuario ([§28.4](../05-codigo-muerto-y-roto.md)) y esa
línea es la que lo deja escrito. El repunte lo puso en rojo **en su propia premisa**.

### 3. `EntrustYPropiedades` — la ruta exige superusuario por decisión escrita

`putCreartodoslosusuarios` lo exige en su propio comentario: crea las cuentas de alumnos,
profesores y acudientes, y «no crea usuarios» fue textual en el alcance que decidió
Joseth. Con un `Usuario` llano, **403**.

**No era un hallazgo sobre la ruta**: la restricción ya estaba decidida y documentada.

### Lo que los tres juntos enseñan, que es el borde del criterio

> **Clasificar por el código HTTP no distingue «el personal puede X» de «sólo un
> superusuario puede X»: los dos afirman 200.**

El discriminador no está en el test, está en **si la ruta exige superusuario** — que es
justo lo que el barrido del [lote I](i.md) mide y lo que la predicción intentaba cruzar.
Con las tres revertidas quedan **38 repuntados**, y los tres que volvieron atrás llevan
el porqué dentro.

---

## La predicción falló, y la razón no es la que parecía

Salió el **segundo caso** de la tabla —rojos que no estaban predichos— pero **no por el
motivo que había escrito**. Al perseguirlo:

```
el regex que usé   ->  superusuario 147   llano 104   solo super 43
el correcto        ->  superusuario 313   llano 246   solo super 76
```

`^  (?:\w+)\s+(api/\S+)` **no casa con las líneas de hallazgo del barrido**, que llevan
`  200  PUT    api/…`: el `\w+` se comía el código y luego exigía `api/` donde había un
verbo. Casaba **sólo con la lista de no juzgables**, que sí tiene la forma
`  VERBO api/…`. **Las 43 «rutas que sólo alcanzó el superusuario» eran otra cosa.**

Con el parseo correcto, `perfiles/creartodoslosusuarios` y `subunidades/forcedelete/{}`
**sí están** en las 76, y el predictor arreglado **habría acertado dos de los tres
rojos** — el tercero, `ExcelTest`, no era predecible por esa vía porque no fue un fallo
de permiso sino del año.

> Es la tercera vez esta noche que el fallo es **una expresión regular que casa con otra
> cosa y devuelve un número creíble**. La señal, otra vez, estaba a la vista: 43 rutas
> «solo del superusuario» cuando el barrido daba 170 contra 145, o sea 25 de diferencia.
> **Un número que no cuadra con otro que ya tienes es la forma barata de cazarlo.**

---

## El `stan` no sale `[OK]`, y no es de este lote

Al correr larastan sobre el árbol de N sale **un error**, y hay que decir las dos cosas
por separado porque son dos afirmaciones distintas:

```
árbol raíz (main, sin N)   ->  Found 1 error
.worktrees/n (main + N)    ->  Found 1 error   (el mismo fichero, la misma línea)
```

**El rojo es de `main` y N no añade ninguno.** Medido por los dos lados y no deducido del
`git diff`, porque «no lo he tocado» y «no lo he provocado» no son la misma frase.

```
tests/Unit/DefinicionDeLosDetectoresTest.php:83
  Constant Tests\Unit\DefinicionDeLosDetectoresTest::NO_SE_FIJA_TODAVIA is unused.
```

La constante es **documentación deliberada** —lleva su porqué al lado: *«una definición
que alguien está cambiando no necesita un centinela, necesita que le dé tiempo a
cambiar»*— y dice que entra el día que cierre el lote H.

### Y aquí me equivoqué, con la prueba en mi propia pantalla

Escribí que **la nota no había vencido**, apoyándome en dos cosas: que el tablero daba H
como «tomado» y que **la regex `PROPIEDAD` seguía teniendo `exig` dentro**. Las dos
observaciones eran ciertas y **la conclusión era falsa**.

`H` cerró y está fundido (`1156dab`). Y `exig` sigue en `PROPIEDAD` **a propósito**: el
arreglo no estrechó la señal, **excluyó la llamada antes de aplicarla**, nueve líneas más
abajo —

```python
NO_ES_PROPIEDAD = re.compile(r'ColumnaSegura::exigir')
...
limpio = NO_ES_PROPIEDAD.sub('', COMENTARIOS.sub(' ', src))
```

— y de paso arregla también la prosa de los comentarios, que la señal leía.

> **Buscar un arreglo por la forma que uno esperaba no es comprobar si está.**

Lo que lo hace peor, y por eso queda escrito: **esas dos líneas estaban en la salida del
`grep` que yo mismo había impreso minutos antes**. Las leí por encima porque estaba
contestando «¿sigue `exig` en `PROPIEDAD`?», que es una pregunta cuya respuesta —sí— **no
dice nada sobre si el problema está resuelto**.

Con H cerrado, **la nota sí ha vencido**, y lo correcto es lo que su autora dejó escrito
como plan: fijar la definición con el valor nuevo y quitar la constante. Ese día era hoy.

No se arregla desde aquí: el fichero es del lote C.

---

## Lo que este lote **no** puede afirmar

`CasoDeContrato.php` lo heredan **130 ficheros**. Correr sólo los que se editan dice que
esos hacen lo que deben; **no dice nada de los otros**. Por eso este lote no se cierra
con `--filter`: se cierra con **la suite entera**, y ése es el único número de la noche
que contesta si los 95 ficheros que no se tocaron siguen en pie.

La medición de cierre de la noche —la de quien coordina— corre **antes** de que N
exista, así que tampoco puede contestarlo. **No son dos veces lo mismo**: una cierra la
noche y la otra cierra este lote.
