# AUD-4 — los escritores de la bitácora

> **Lote de la noche del 25 ago 2026, sesión `8myvc-9a`.** Rama
> `feat/auditoria-escritores`, árbol `.worktrees/9a`, base
> `simonbolivar_testing_9a` (**94 tablas, 2.351 usuarios**).
> Es la **fase 4** de [18-auditoria.md](../18-auditoria.md), y va detrás de
> [aud-3](../noche-2026-08-24/aud-3.md), que dejó la tabla y el escritor único
> escritos y **sin que los llamara nadie**, a propósito.
>
> **Lo que este lote entrega:** que la auditoría tenga **quién la escriba**. Dos
> mitades: los diez `INSERT INTO bitacoras` sueltos pasan al servicio, y las
> cinco familias que hoy no graban nada empiezan a grabar. Sin la segunda, la
> pantalla que pidió el colegio no tiene filas que enseñar.

---

## 0. Lo que NO se ha tocado, y es lo primero porque es lo que se puede romper

**`bitacoras` sigue intacta: no se ha quitado ni un `INSERT`.** Los diez escritores
viejos escriben exactamente lo que escribían, y al lado se añade la línea nueva.
Hay un test que lo fija —`test_el_rastro_viejo_sigue_escribiendose_al_lado_del_nuevo`—
y no es una redundancia: es la mitad del encargo que se puede romper sin que
ningún otro caso se entere.

El motivo es de despliegue y no de diseño: `app/` es **copia real en cada uno de
los dieciséis colegios**, y dos pantallas del front vivo leen `bitacoras`
(`historiales/nota-detalle` y su gemela de definitivas). Retirar el `INSERT` viejo
el día que esto se funda las dejaría en blanco durante todo el despliegue. La
retirada va detrás del front (JUB-1), no delante.

Tampoco se ha tocado el esquema: **ninguna migración en este lote.** Y ningún
`UPDATE` ni `DELETE` sobre `auditoria` — lo sigue fijando
`AuditoriaEscritorUnicoTest`, que recorre `app/` entero y dice cuántos ficheros
revisó.

---

## 1. Lo entregado

| | |
|---|---|
| Los diez escritores viejos, traducidos | 8 ficheros, la llamada **al lado** del `INSERT` viejo |
| Las cinco familias | asistencia, comportamiento, disciplina, situaciones, frases |
| Dos huecos que no eran de ninguna de las dos | `DefinitivasPeriodosController`, las ramas de crear |
| El nombre del alumno, congelado | `app/Support/NombreDelAlumno.php` |
| Los tests | `AuditoriaDeLosDiezEscritoresTest`, `AuditoriaDeLasCincoFamiliasTest` |

---

## 2. La `descripcion` vacía, que resultó ser una de dos causas y no la otra

Llegó del front a mitad del lote, medido en Chrome contra el cuerpo crudo:
**`GET /api/bitacoras` manda `descripcion: null` en las 22 filas.** Es la columna
que dice *qué pasó*, y el criterio que trae detrás cambia lo que cuenta como
terminado:

> **Una fila de auditoría sin descripción legible no cuenta como cableada.** Si
> las cinco familias graban con la descripción vacía, AUD-4 entrega mucho volumen
> y poca información, y la pantalla sigue sin poder enseñar nada — sólo que ahora
> con diez veces más filas que revisar.

El síntoma admite dos causas y **sólo una la arregla este lote**, así que lo
primero fue separarlas:

- **¿No se lee?** No: `BitacorasController::getIndex` hace `SELECT *`. La columna
  viaja.
- **¿No se escribe?** **Sí, y ésa es la causa.** De los **diez** escritores de
  bitácora **sólo dos** nombran `descripcion` (`Services\Login` y
  `Services\Sesion`), y los dos son sucesos de sesión. `getIndex` filtra por
  `created_by`, o sea que un usuario lista **sus propias** filas — y las suyas
  salen todas de los otros ocho, que nunca la escriben. De ahí las 22 en blanco.

**`Auditoria` no puede repetirlo por la forma de la clase**: `guardar()` hace
`$this->fila['resumen'] ??= $this->frase()`, así que la columna **nunca sale
null**. Pero «no null» no es «legible», y la frase de serie se construye con lo
que hay en la fila: sin el nombre del alumno dice *«Fulano borró ausencia 4821»*
—un verbo, el nombre de la entidad y un id—, que es peligrosamente parecido a
rellenar con el nombre de la tabla.

Por eso este lote añade `App\Support\NombreDelAlumno` y lo pasa en todas las
familias que tocan a una persona. La misma frase pasa a ser *«Fulano borró
ausencia 4821 de Ana Pérez»*.

> **Y el nombre va copiado dentro de la fila, no resuelto al leer.** Es la §2.4
> de aud-3: `auditoria` no tiene claves foráneas a propósito, para que la línea se
> pueda leer dentro de tres años aunque el alumno ya no exista. Un `alumno_id`
> suelto no cumple eso; el nombre congelado sí.

### El coste, y los dos sitios donde había que pensarlo

Una consulta por línea es gratis en el caso normal —una petición escribe una
falta, una frase, una nota—, pero **hay dos caminos que escriben en bucle**: el
lector de tardanzas sube el recreo entero y `notas/lote` guarda una columna de la
rejilla. Ahí una consulta por fila **duplicaría** el coste del endpoint, que es
justo la regresión que este repositorio persigue con medición delante.

`NombreDelAlumno::deVarios()` resuelve el lote entero en **una** consulta antes de
entrar al bucle y deja el memo lleno; dentro, `de()` ya no consulta nada.

---

## 3. Lo que encontró el detector después de traducir los diez

`tools/escrituras-sin-auditoria.php` imprime su población, y eso es lo que lo hace
utilizable. Pero **la segunda pasada dijo más que la primera**, y no por medir
mejor: por medir **otra cosa**.

Al traducir los diez, el método pasó a contar «audita menos veces de las que
escribe», y ahí salieron **cuatro sitios** que no estaban en la lista de los diez
ni en la de las cinco familias. Dos eran ruido explicable —el propio `INSERT INTO
bitacoras` es una escritura sin auditoría propia— y **dos eran huecos de verdad**:

| Dónde | Qué pasaba |
|---|---|
| `DefinitivasPeriodosController::putUpdate`, rama sin `nf_id` | escribe una definitiva a mano y **no dejaba rastro en ninguna de las dos tablas** |
| `putUpdateRecuperacion`, rama sin `rf_id` | lo mismo con la recuperación final |

No son escritores viejos: son **huecos**. Y la primera es la rama que el front usa
de verdad cuando no tiene `nf_id` a mano (§2.3 de 10-definitivas.md), o sea que
**una definitiva tecleada a mano por ese camino era invisible para el colegio**.
Las dos quedan cerradas y con test.

Una tercera pasada, ya con las familias dentro, señaló los bucles de
`dependencias` de `DisciplinaController` — cada vuelta cambia el `become_id` de
**otra** falta, y la línea tiene que colgar de esa otra falta y no del proceso que
se está editando. También cerrado.

> **Lo que esto dice del detector, que es lo que hay que llevarse:** un detector
> da sitios donde mirar, y **cambia lo que ve cuando cambia el código que mira**.
> Correrlo una vez al principio habría dado la lista de los diez y nada más. Los
> tres huecos salieron de volver a correrlo **después de cada mitad**.

### Y lo que este detector NO cuenta, que es la mitad de mi lote

Su línea de población dice, literalmente, `escrituras de datos … 252
(DB::insert/update/delete/statement…)`. Es honesta y es exacta — y por eso mismo
**no cuenta las escrituras de Eloquent**.

De las cinco familias, **cuatro controladores enteros no aparecen nunca en su
listado**: `AusenciasController`, `FrasesController`, `FrasesAsignaturaController`
y `DefinicionesComportamientoController` escriben con `$modelo->save()` y
`$modelo->delete()`. Si hubiera trabajado desde su lista de «149 métodos sin
rastro», habría cerrado el lote **dejando fuera las ausencias y dos de las tres
familias de frases**, y el detector me habría dado luz verde.

Los dos números al lado, que es lo que lo hace visible:

| | |
|---|---|
| métodos con rastro nuevo **según el detector** | 32 |
| llamadas a `Auditoria::registrar()` **que hay en `app/`** | 52, en 21 ficheros |

**Los dos son correctos y miden cosas distintas.** El primero cuenta métodos que
hacen escrituras crudas; el segundo cuenta llamadas. Leer el 32 como «ya está todo»
es el error, y es de la familia de la §5.5: *el instrumento correcto sobre el
objeto equivocado no se ve mirando el resultado, porque el resultado es correcto.*

---

## 4. La pregunta que ningún detector de escrituras contesta

La trajo la coordinación con una cadena medida en el front, y es mejor argumento
del append-only que el que estaba escrito:

> **Durante años, quien borraba sus propios intentos fallidos de entrar era el
> mismo que los generaba.** Los intentos fallidos son filas de `bitacoras`, y en la
> aplicación desplegada hay un botón —visible para **cualquier usuario de los
> dieciséis colegios**— que llama a `DELETE bitacoras/destroy/{id}` sobre ellos.
>
> **Una tabla de auditoría que el auditado puede editar es un formulario de
> opinión.**

Eso no lo encuentra ningún barrido de escrituras: **es un `DELETE`, vive en otra
pantalla, en un fichero que no se llama bitácora, llamando a un método que no se
llama borrar.** La pregunta que sí lo encuentra no es *«¿quién escribe aquí?»*
sino **«¿quién puede quitar de aquí?»**.

Aplicada a este lote, esa pregunta es la que decidió **dónde** va cada llamada de
borrado: **antes** del `delete()` y con `de(...)` puesto, nunca después. Si la
línea no se lleva el valor viejo dentro, no queda en ningún sitio — la fila de la
tabla ya está en la papelera. Son ocho sitios: ausencias (tres caminos), faltas
disciplinarias, ordinales del manual, notas de comportamiento, definiciones y las
tres familias de frases.

**No se ha tocado nada de `bitacoras` por esto.** La retirada del endpoint es JUB-1
y va por el lado del front, con orden obligatorio: los botones quitados y
**desplegados** primero.

---

## 5. Lo que se ha dejado fuera a propósito

No es una lista de pendientes: cada uno tiene un motivo que no es «no dio tiempo».

### 5.1 Tres tablas que escriben y no entran en el vocabulario

`dis_proceso_ordinales`, `dis_configuraciones` y `frases`—en su forma de catálogo
copiado al crear un año— escriben sin tener nombre en `Auditoria::ENTIDADES`.

**Añadir un nombre a esa constante es editar el vocabulario cerrado del servicio**,
y eso es deliberadamente un acto y no un `string` suelto (18 §2.3): es lo que
impide que vuelvan a convivir `Nota`, `NF_UPDATE` y `Nueva subunidad` en la misma
columna. Una sesión sola no lo abre.

Lo que se ha hecho en su lugar, donde tenía sentido: **colgar la línea de la
entidad que sí existe.** Poner y quitar un ordinal de una falta se registra como
una edición de **la falta**, no como un alta de `dis_proceso_ordinales` — y además
así sale en el historial de esa falta, que es donde la pantalla lo va a buscar.

### 5.2 `accion_restaurativa`, que está en el vocabulario y no la escribe nadie

Al revés que las anteriores: `dis_acciones_restaurativas` **tiene** entrada en
`ENTIDADES` y **su única mención en todo `app/` es esa constante**. No hay ningún
código que lea ni escriba esa tabla. Queda dicho porque un vocabulario con una
entrada que nadie usa se lee como «esto ya está cubierto».

### 5.3 Los dos `postIndex` rotos de asistencias

`Tardanzas/AsistenciasController::postIndex` y su gemelo de `AppMobile` **no
escriben**: el INSERT declara `:asignatura_id` y el array de valores no lo trae, y
detrás espera un `$datos->id` sobre un array, que en PHP 8 es un Error. Están
enrutados y documentados en 05.

**Instrumentarlos escribiría una línea en un camino por el que no pasa nadie**, y
la fase 5 la enseñaría como una falta que se puso. Va un comentario en su sitio
diciendo cuál es la llamada y qué decisión falta antes. Con ruta y roto se
documenta.

### 5.4 El valor viejo de `years/update`

`YearsController::putUpdate` audita el año con `a(...)` y **sin `de(...)`**. La
fila vieja ya se ha perdido doce líneas antes, cuando el modelo se rellena campo a
campo con `Request::input(..., $year->…)`. Recuperarla exige releer el año antes de
tocarlo, y eso cambia la forma del método — no es de este lote.

### 5.5 El nombre del alumno en los dos bucles

En `TSubirController::postIndex` y `NotasController::putLote` el nombre se resuelve
con `deVarios()` **antes** del bucle, una consulta para todo el lote. Es lo que
evita duplicar el coste de los dos endpoints de más volumen.

---

## 6. Lo que cambia para un cliente

**Nada.** Ni un cuerpo, ni un nombre de campo, ni una ruta, ni un código HTTP.

Las 542 rutas siguen siendo 542 y devuelven lo mismo. Lo único que cambia es que
detrás de treinta y tantos métodos queda escrita una fila en una tabla que ningún
cliente lee todavía —la pantalla que la lee es la fase 5, que no existe— y que en
los diez escritores viejos **se escribe la fila nueva además de la de siempre**.

---

## 7. El volumen, que la pantalla del front necesita antes de abrirse

Lo pidió la coordinación con un motivo concreto: **`GET /api/bitacoras` manda las
22 filas enteras y la rejilla las pinta de golpe** —la barra de páginas existe con
`display:none`, así que ni el servidor ni el cliente reparten—. Con 22 filas da
igual; con lo de este lote, no.

Medido sobre `simonbolivar_testing_9a`, que es **la copia de UN colegio** (los
dieciséis no son iguales), repartido entre **190 días lectivos**:

| Familia (tabla) | filas | por día |
|---|---:|---:|
| asistencia (`ausencias`) | 1.069 | 5,6 |
| comportamiento (`nota_comportamiento`) | 214 | 1,1 |
| comportamiento (`definiciones_comportamiento`) | 151 | 0,8 |
| frases (`frases`) | 426 | 2,2 |
| frases (`frases_asignatura`) | 149 | 0,8 |
| libro rojo, disciplina, frases de preescolar | **0** | 0 |
| **las cinco familias** | **2.009** | **10,6** |

Y el rastro que ya existía, para tener con qué comparar: `notas` tiene **19.511**
filas (**102,7/día**) y `notas_finales` **2.276** (12,0/día).

**El orden de magnitud, que es lo que se pedía: entre veinte y veinticinco mil
líneas al año por colegio, de las cuales unas dos mil son de las cinco familias
nuevas.** La pantalla pasa de 22 filas a decenas de miles. **Repartir en páginas
deja de ser una mejora y pasa a ser un requisito**, en el servidor y en el cliente.

### Las tres cosas que este número NO dice, y hay que decirlas

1. **Es una cota inferior, no una estimación.** Se cuentan **filas que existen**, y
   una fila existe una vez pero se edita muchas: cada edición y cada borrado son
   **una línea más** y no mueven el contador. Lo que hay arriba es «al menos
   esto».
2. **Este colegio no usa el módulo de disciplina.** `dis_procesos`,
   `dis_libro_rojo` y `dis_proceso_ordinales` están a cero, así que la familia
   entera aporta 0 aquí y **no** aportará 0 en un colegio que sí lo use. El libro
   rojo además nace solo al abrir el observador: en la primera visita de un grupo
   son cuarenta líneas de golpe (marcadas `porElSistema`, y por eso la pantalla
   las puede filtrar).
3. **190 días lectivos es un supuesto**, puesto aquí para que se pueda discutir en
   vez de quedar escondido dentro de una división.

### Lo que NO se ha podido medir, y por qué

Se pidió también **cuántas de las filas de `bitacoras` de hoy son «no-cambios»** —
`putUpdate` fabrica una fila aunque se guarde el mismo valor encima, que no miente
pero mete ruido, y con cinco familias más ese ruido deja de ser filtrable a ojo.

**No se puede contestar desde aquí: `bitacoras` tiene 0 filas en esta base.** El
seed de tests llega con la tabla vacía —es lo que obligó a `QuienCambioLaNotaTest`
a medir por el viaje de ida y vuelta— así que cualquier número que diera sería
inventado. La consulta que lo contesta está escrita y corre; le falta la copia de
producción:

```sql
SELECT COUNT(*) AS con_valores,
       SUM(affected_element_old_value_int = affected_element_new_value_int) AS no_cambios
  FROM bitacoras
 WHERE affected_element_old_value_int IS NOT NULL
   AND affected_element_new_value_int IS NOT NULL;
```

> **Y una cosa que sí se puede decir sin medirla, porque es de forma:** en
> `auditoria` un no-cambio **se reconoce solo**, sin columna nueva y sin filtro
> especial — `valor_anterior` y `valor_nuevo` quedan iguales (18 §4.1). En
> `bitacoras` también, pero **sólo en las filas que traen los dos valores**; las de
> los otros escritores no los traen y ahí no hay forma de distinguirlo. O sea que
> el ruido que preocupa **es filtrable en la tabla nueva y no lo es del todo en la
> vieja**, y eso no cambia la decisión de dejar de fabricarlas —que es de Joseth—
> pero sí la abarata.

---

## 8. Lo comprobado, y con qué

Dos clases nuevas, **todas por el viaje de ida y vuelta**: se llama a la API de
verdad y se lee **la fila que queda**, nunca el código de respuesta.

| Clase | Qué fija |
|---|---|
| `AuditoriaDeLosDiezEscritoresTest` | los diez, y que **el rastro viejo sigue escribiéndose** |
| `AuditoriaDeLasCincoFamiliasTest` | las cinco, el borrado con su valor viejo, y la descripción legible |

**Por qué no vale mirar el 200 aquí, más que en ningún otro sitio del repo:**
`Auditoria::guardar()` se traga cualquier excepción a propósito (18 §4.3) — una
entidad mal escrita, una columna que no existe o un tipo que no encaja **no rompen
la petición**: devuelven `null`, dejan la fila en el log y la respuesta sale 200
igual de contenta. Un caso que comprobara el código de respuesta pasaría en verde
con la auditoría entera perdida.

Tres de los casos existen porque comprueban cosas que **sólo se pueden romper
desde fuera del servicio**, no dentro:

- `test_el_rastro_viejo_sigue_escribiendose_al_lado_del_nuevo` — el único que se
  entera si alguien retira el `INSERT` viejo antes de tiempo.
- `test_si_el_cambio_se_deshace_la_linea_de_la_familia_tambien` — `aud-3` ya prueba
  esto **sobre el servicio**; aquí se prueba **desde un llamante**, que es donde de
  verdad se rompe: lo que falla al instrumentar no es la clase, es meter la llamada
  donde la transacción no la alcanza.
- `test_el_libro_rojo_que_nace_solo_es_del_sistema` — que la creación automática no
  se anote a nombre de quien abrió la pantalla.

**Herramientas:** `pint` en verde (290 ficheros; formatea middleware, servicios,
`Support` y `tests` — los 113 controladores están fuera de su alcance a propósito)
y **larastan nivel 7 sin ningún error nuevo**.

> **Un error de larastan que NO es de este lote y queda dicho para que no se cuente
> como mío:** `tests/Contrato/BoletinFortalezaDebilidadTest.php:104` llama a
> `assertStatus()` con dos parámetros y sólo acepta uno — el mensaje que le pasan
> **no se muestra nunca**. Entró con `de42d90`, de otra sesión, y el fichero no
> está en mi diff. Es de una línea y no lo toco: un fichero, un dueño.

---

## 9. Lo que me retiro

**Una cosa, y la traigo yo porque salió más barata que el trabajo que habría
mandado hacer al sitio equivocado.**

Al instrumentar las ausencias escribí, en el docblock del ayudante, que el nombre
del alumno **no se buscaba** porque «costaría una consulta por falta y la
asistencia se toma alumno a alumno, hasta 40 en un recreo».

**El razonamiento estaba mal y el número también.** Esas 40 faltas no son un bucle:
son **40 peticiones HTTP distintas**, cada una con su resolución de contexto de 5 a
8 consultas ya encima. Una consulta más por petición no se nota. Los únicos sitios
donde de verdad hay un bucle son **dos** —el lector de tardanzas y `notas/lote`—, y
ésos se resuelven con **una** consulta para el lote entero.

O sea que había cambiado una pérdida real de información —una línea de auditoría
que no se puede leer— por un ahorro que no existía. El comentario está corregido en
su sitio y no sólo aquí, que es donde alguien lo va a leer.

Lo que lo destapó no fue releerlo: fue el criterio que llegó del front a mitad del
lote —*una línea sin descripción legible no cuenta como cableada*— obligando a
mirar **qué dice la frase que sale**, y no si la columna tiene algo dentro.
