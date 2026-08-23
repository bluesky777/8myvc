# Lote C — La rejilla: quién escribe una definitiva y con qué candado

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/c`, rama `fix/lote-c-rejilla-definitivas`, base
> `simonbolivar_testing_c`.
>
> Secciones asignadas del 05: **§89–92**. Están escritas con ese número desde el
> principio para que quien coordina no tenga que renumerar al fundir.

La pregunta del lote, tal como la dejó escrita
[15 §5](../15-la-noche-en-paralelo.md): **¿cuál de estas trece rutas escribe o
borra en la rejilla sin preguntar por el interruptor del periodo?**

La respuesta corta, medida y no leída: **de las trece, ninguna escribe en las
notas.** Lo que salió al mirarlas una a una es otra cosa, y es peor.

---

## §89. `boletines2/destroy` y `boletines3/destroy` no borran un boletín: borran un ALUMNO

Y son **las dos copias que la §72 no miró** — la sección que, el mismo día,
dejó escrita la lección de por qué eso pasa.

### 89.1 La operación tiene cuatro puertas y la §72 cerró dos

`Alumno::find($id)` seguido de `->delete()` está **cuatro veces** en `app/`:

| Ruta | Guard antes de esta noche | Sobre qué tabla opera |
|---|---|---|
| `DELETE api/alumnos/destroy/{id}` | `Autoriza::puedeEditarAlumnos` | `alumnos` |
| `DELETE api/editnota/destroy/{id}` | `Autoriza::puedeEditarAlumnos` (§72) | `alumnos` |
| `DELETE api/boletines2/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |
| `DELETE api/boletines3/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |

La [§72](../05-codigo-muerto-y-roto.md) cerró la de `editnota` y escribió al
hacerlo:

> Cerrar una de tres es lo que pasa cuando se arregla **el sitio que se está
> mirando y no la operación**.

Y se cerró sobre la población «`EditnotaController`», que es un controlador y no
una operación. Las dos de boletines son la misma copia byte a byte y siguieron
abiertas. **La sección que escribió la regla la incumplió en el mismo commit**,
lo cual es exactamente lo que la regla predice: quien cierra mira el fichero que
tiene abierto.

Que `boletines2/destroy` opera sobre `alumnos` **ya estaba medido** —está en la
`TABLA_DE_ID` del barrido, [§21.1](../05-codigo-muerto-y-roto.md), y en el
[09](../09-pendientes.md)—, y por eso este caso vale doble: es la
[§53](../05-codigo-muerto-y-roto.md) otra vez. **Medir una ruta no es haberla
juzgado.** El dato existía, se usó para que el barrido apuntara al id correcto, y
nadie preguntó si la ruta debía poder hacer eso.

### 89.2 El hueco era real, y es el mismo que midió la §72.1

`puedeEditarAlumnos` es superusuario **o** profesor con `profes_can_edit_alumnos`,
apagada en los dieciséis colegios. Medido antes de tocar nada, con un token de
profesor y la bandera apagada:

| Ruta | Antes | Alumno en la papelera |
|---|---|---|
| `alumnos/destroy` | 400 | no |
| `editnota/destroy` | 403 | no |
| `boletines2/destroy` | **200** | **sí** |
| `boletines3/destroy` | **200** | **sí** |

### 89.3 Cómo queda, y por qué no apaga ninguna pantalla

Las dos pasan a exigir `Autoriza::puedeEditarAlumnos`, que **no es un criterio
nuevo**: es el que ya decidieron sus dos hermanas. Y el único cliente que nombra
estos controladores es `myvc_front`, en `BoletinesApi.ts`, que declara
`detailed-notas` y `detailed-notas-group` — no `destroy`. `myvc_front_2` y
`myvc_flutter` no nombran `boletines2` ni `boletines3` en **ningún** fichero
(buscado sobre los 555 y 411 ficheros de cada repo, no sobre los `.ts`).

Lo fija `BoletinesBorranAlumnosTest`, con las cuatro puertas en un mismo caso y
el viaje de ida del superusuario para que se vea que se cerró la puerta y no la
casa.

**Los cuatro códigos no coinciden y se fijan tal cual**: `alumnos/destroy`
contesta 400 porque lo tiene escrito a mano en el controlador (legacy), y las
otras tres 403 porque las cerró `Autoriza::exigir`, que es código nuevo. Lo que
tienen en común es **el resultado**, que es lo que decide si un alumno está en la
papelera. Unificar el 400 cambia el contrato de una ruta que los clientes sí
llaman: **se anota, no se hace**, y no es de este lote.

### 89.4 Comprobado al revés

Quitando **solo** el guard de `boletines3` caen exactamente dos casos: el suyo del
proveedor de datos y el de las cuatro puertas. O sea que los dos caminos están
cubiertos por separado y no hay uno tapando al otro.

---

## §90. La respuesta del lote: `calcular-grupo-periodo` reescribe la rejilla de un periodo cerrado

La pregunta del lote era **cuál de las trece escribe en la rejilla sin preguntar
por el interruptor del periodo**. Ninguna de las trece. La que lo hace es una
ruta que **no está en la lista de trece porque ya tenía test** — el de la
inyección, [§63](../05-codigo-muerto-y-roto.md), que miraba otra cosa.

> **Medir una ruta no es haberla juzgado.** Es la segunda vez esta noche: en la
> §89 el dato existía en la `TABLA_DE_ID` del barrido, y aquí existe un test de
> contrato entero sobre la misma ruta.

### 90.1 Qué hace, medido

Con un token de **profesor**, los cuatro periodos del año cerrados
(`profes_pueden_editar_notas = 0` y `profes_pueden_nivelar = 0`) y el `periodo_id`
del periodo cerrado en el cuerpo:

| | |
|---|---|
| Respuesta | **200 `Calculado`** |
| Definitivas del grupo y periodo antes | 463 |
| Definitivas después | 463 |
| **Filas que sobrevivieron** | **0** |

Las 463 se borran y se vuelven a insertar: el conteo no se mueve y **los ids
cambian todos** (el máximo pasó de 7.228.862 a 7.256.524). Contar filas habría
dicho que no pasó nada, que es justo mirar el estado en vez del resultado. Y cada
fila nueva lleva `updated_by` del profesor que disparó el botón, así que además
reescribe la respuesta de «[quién cambió esta definitiva](../05-codigo-muerto-y-roto.md)»
(§73) para 463 notas de golpe.

Lo que **sí** respeta: el `DELETE` filtra `(manual is null or manual=0) and
(recuperada is null or recuperada=0)`, así que lo puesto a mano sobrevive — **al
revés que la §71**, que tenía ese mismo criterio invertido. Medido sobre el grupo
con 40 manuales y 3 recuperadas: las 43 siguen ahí.

### 90.2 Es la única de las ocho de su controlador que no pregunta

| Rutas de `DefinitivasPeriodosController` | ¿Pregunta? |
|---|---|
| `update`, `update-recuperacion`, `toggle-manual`, `toggle-recuperada` | sí |
| `eliminar-recuperada`, `destroy/{id}`, `arreglar-duplicados` | sí |
| **`calcular-grupo-periodo`** | **no** |

Y el interruptor **está puesto y funciona**: con el mismo token y el mismo
periodo cerrado, `definitivas_periodos/update` sobre una definitiva de ese
periodo contesta **400** y no la cambia. O sea que no es que el candado esté roto:
es que hay una puerta que no lo consulta, y es la que escribe más filas de una vez
que ninguna de las que sí lo consultan.

### 90.3 Cómo se escondió, que es la parte que se repite

No fue un detector ciego. `tools/escrituras-en-las-notas.py` la lista como
**«NO pregunta» desde que existe**. Lo que falló es la tabla que convierte esa
lista en veredictos, la de la [§77.2](../05-codigo-muerto-y-roto.md):

| Método | Ya estaba (según la §77.2) |
|---|---|
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo` | «§71, cortada con 410» |

**La cortada con 410 es la vecina.** La §71 cortó
`putCalcularNotasFinalesAsignatura`, que es la línea siguiente del mismo fichero
de rutas:

```
academico.php:124  PUT definitivas_periodos/calcular-grupo-periodo              <-- viva, escribe
academico.php:125  PUT definitivas_periodos/calcular-notas-finales-asignatura   <-- 410 desde la §71
```

Mismo controlador, mismo `auth.personal`, nombres que empiezan igual y una línea
de distancia. La §77.2 leyó cuatro métodos «uno a uno —que es lo único que
convierte una lista en un veredicto—» y en uno de los cuatro el veredicto se le
atribuyó al de al lado.

> **Un detector que acierta no basta si el veredicto se escribe en una tabla a
> mano.** La lista estuvo bien las dos veces; lo que se equivocó es el renglón
> que decía que ya estaba resuelta. Y un renglón que dice «ya está» es más caro
> que un falso negativo del detector, porque **apaga la pregunta**.

Por eso el último caso del test golpea **las dos rutas en la misma petición** y
compara los dos códigos en un solo `assertSame`: `200` y `410`, uno al lado del
otro. Dos números en la misma línea no se confunden; una fila de una tabla, sí.

### 90.4 Qué se ha hecho y qué no

**Hecho**: `CalcularGrupoPeriodoTest`, cuatro casos, que fijan lo que hay hoy
—200, cero supervivientes, las manuales a salvo, la hermana en 400 y la vecina en
410— con el porqué al lado de cada valor. Un test que fija lo que hay fija también
lo que está mal: el día que se cierre, caen dos de los cuatro y ahí está escrito
qué se decidió.

**No hecho, y no por falta de tiempo**: ponerle el candado. Ver `## PARA JOSETH`.

### 90.5 Y una vecina más, anotada al pasar

`GET api/definitivas_periodos/arreglar-duplicados` es un **GET que hace `DELETE
FROM notas_finales`**, y su ruta **no lleva `auth.personal`**: va con el guard por
defecto, `auth.token`. Lo único que la cierra es
`User::pueden_modificar_definitivas` dentro del controlador, que aborta 400 para
quien no sea profesor o superusuario — o sea que hoy **no** hay hueco. Se anota
porque la protección está en el sitio frágil: el día que alguien mueva esa
llamada de sitio, la ruta queda abierta a cualquier token, alumnos incluidos, y
borra filas de `notas_finales`. Es de mi lote y no lo toco porque cambiar el guard
de una ruta que hoy no tiene hueco es ruido en una noche de seis sesiones; queda
dicho aquí.

---

## PARA JOSETH

### 1. ¿Se le pone el candado del periodo a «Calcular definitivas per N»? (§90)

**La pregunta**: hoy un profesor recalcula —borra y reescribe— las definitivas
automáticas de un periodo que el colegio ha cerrado. Ponerle
`pueden_modificar_definitivas` lo cierra en una línea. **No se ha hecho porque
apaga algo**, y hay que decidirlo mirando qué apaga.

**Qué apaga, medido en el front**: `myvc_front` llama a esta ruta en **tres**
sitios, y sólo dos son el botón.

| Dónde | Qué pasaría con un 400 |
|---|---|
| `informes.html:13` → `InformesCtrl.ts:410` | el botón «Calcular per N» sale con un `toastr.error`. Es lo que se querría |
| `InformesCtrl.ts:451` | el bucle que calcula todos los grupos de un periodo: `toastr.warning` y sigue con el siguiente |
| **`InformesCtrl.ts:499`, dentro de `verBoletinesGrupo`** | **el profesor no puede abrir los boletines de ese grupo** |

El tercero es el que decide. `verBoletinesGrupo` sólo llama al cálculo cuando el
periodo está en `periodos_desactualizados` **y** el grupo está dentro; y si el
cálculo falla, muestra un aviso y **no llama a `verBoletinesGrupoCargar`**. O sea
que con el periodo cerrado y las definitivas marcadas como desactualizadas, un
profesor se quedaría sin poder abrir el boletín del grupo.

**Hay precedente y no es el mismo caso**: en [05 §47.2](../05-codigo-muerto-y-roto.md)
decidiste, para `unidades/de-asignatura-periodo`, que con el periodo cerrado la
pantalla **enseñe lo que hay y no cree nada** — para eso existe
`User::permiteEditarNotas`, que contesta en vez de abortar. Aquí valdría lo mismo:
no recalcular y dejar que el boletín se abra con las definitivas que ya hay. La
diferencia con aquél es que aquél es una lectura que de paso escribe, y éste es un
botón que escribe y que además una pantalla dispara sola.

Las tres formas, para que la decisión sea entre cosas y no entre palabras:

1. **Abortar 400** como sus siete hermanas. Cierra del todo; deja a un profesor sin
   abrir el boletín de un grupo desactualizado en periodo cerrado.
2. **No recalcular y contestar 200** (la forma de la §47.2). El boletín se abre con
   lo que hay. Contrapartida: contesta `Calculado` sin haber calculado, que es la
   familia de «[respuestas que mienten](../05-codigo-muerto-y-roto.md)» (§74).
3. **No recalcular y decirlo** — 200 con otro cuerpo, o 409. Lo más honesto y lo
   único que **cambia el contrato** de una ruta que el front sí llama.

No decido ninguna. Queda fijado lo que hay con `CalcularGrupoPeriodoTest`.

### 2. Lo que hay que corregir en el 05, y no es mío (§90.3)

La fila de `putCalcularGrupoPeriodo` en la tabla de la **§77.2** dice «§71, cortada
con 410» y eso es falso: la cortada es su vecina,
`putCalcularNotasFinalesAsignatura`. Como esa tabla es la que convierte los cuatro
«NO pregunta» del detector en veredictos, **la fila fabrica un veredicto falso y lo
deja escrito**. Lo lleva quien coordina al fundir el 05; queda medido aquí y con
test.
