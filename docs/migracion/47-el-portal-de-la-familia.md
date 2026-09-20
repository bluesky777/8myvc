# El portal de la familia, el tablero del día y el vocabulario del paso

**Autorizado por Joseth el 20 sep 2026** con el alcance delante, en respuesta a *«termina lo del
proceso de matrículas, todos los endpoints que flutter y front necesitan»*. Se le pusieron tres
alcances y eligió el segundo: **cerrar lo decidido sin dueño, más el portal de la familia**.

**Ya no queda nada fuera: la pantalla 13 y la «fase 4» las retiró él mismo** ese mismo día, en la
respuesta que cerró el pagaré (§9). Antes de ella este párrafo decía *«fuera queda una sola cosa:
la firma del contrato»* — y la cosa no existía. La pasarela la había retirado unas horas antes
—*«cada colegio maneja su propio sistema de cartera y contabilidad, unos ni tienen pagos en línea.
Eso no importa»*—, y de esa respuesta sale además que **la pantalla 12 no se puede construir
aquí**. Todo, en la §9.

**Diez rutas.** El router queda en **644** contado con `route:list --json` en `.worktrees/mat`.
**SIN FUNDIR: hay que recontarlas en el ÁRBOL PRINCIPAL el día que entren** — que es la frase que
lleva salvando este número las últimas seis veces, y la única razón de que no envejezca a mentira.

---

## 1. De dónde sale el alcance: el censo de las veintisiete pantallas

Antes de escribir nada se cruzaron **las quince pantallas de `myvc_front/PANTALLAS-MATRICULA.md`**
y **las doce de `myvc_flutter/docs/estaciones.md`** contra las rutas que ya existían. El resultado
es lo que decidió qué se construye:

| | quién la espera | ¿existía? |
|---|---|---|
| 07–12 · las estaciones | flutter, las doce | **sí**, las nueve de `estaciones/*` (20 sep) |
| 01 · armar el recorrido | `app2` | **sí**, `requisitos/*` |
| 03 · vender el formulario | `app2` | **sí**, las diez del formulario impreso (19–20 sep) |
| 14 · asignar grupo y matricular | `app2` | **sí**, `matriculas/*` |
| **15 · el tablero del día** | `app2` | **no** |
| **04 · mi proceso** | la familia | **no** |
| **02, 05, 06 · el portal** | la familia | **no** |
| ~~13 · firmar el contrato~~ | — | **RETIRADA por Joseth el 20 sep**: la pantalla no describe nada que su producto haga (§9) |

Y dos huecos más que no son pantallas y que el censo destapó de camino:

- **El aviso al acudiente no lo escribía nadie.** Joseth decidió el 20 sep que *«al acudiente se le
  avisa en CADA estación»* (`estaciones.md` §2.2 bis) y **ninguna línea del backend lo mandaba**.
- **El vocabulario de `requisitos_alumno.estado` seguía abierto**, y el 46 §2 lo llamaba *«lo
  primero que hay que cerrar»*.

---

## 2. EL FALLO VIVO QUE ESTO DESTAPÓ, y que ninguna lectura del código habría visto

**Corregir una observación cerraba el paso y lo firmaba.**

`myvc_front/app2/.../prematriculas.ts::guardarObservacion` manda el cuerpo entero al guardar el
texto:

```ts
this.requisitosApi.deAlumno({
    requisito_alumno_id: observacion.requisito_alumno_id,
    estado: observacion.estado ?? '',      // <- aquí
    descripcion: texto,
});
```

Y en `postAlumno`, la cadena vacía no era `falta` ni `devuelto`, así que entraba por la rama de
cerrar:

```php
$reabre = $pedido === 'falta' || $pedido === 'devuelto';   // false para ''
// → cerrado_por = COALESCE(cerrado_por, quien escribió la observación)
// → cerrado_at  = COALESCE(cerrado_at,  ahora)
```

O sea: **quien corrige una tilde en la observación de un requisito en blanco lo cierra, lo firma
con su nombre y le pone su hora**, sin error y con un `'Actualizado'` de vuelta. Y `getRecorrido`
lo lee como cumplido, porque su regla es *«cualquier cosa que no sea `falta`»*.

Es **la misma familia** que el fallo del 1 sep 2026, cometido por el otro lado: aquél borraba el
estado cuando no venía, éste lo cerraba cuando venía vacío. *Un endpoint que escribe lo que le
manden acaba teniendo tantos fallos como formas tenga de mandarle nada.*

### La corrección que vino de escribir el test, y que cambia la historia

Este documento decía primero *«hay filas con `estado` NULL, del `UPDATE` roto»*. **Es falso, y lo
delató el test al no poder construir el caso**: la columna es `varchar(255) NOT NULL DEFAULT
'Falta'`, así que aquel `UPDATE` **nunca pudo escribir NULL**. En un servidor no estricto —el
docker— `SET estado=NULL` sobre una columna `NOT NULL` escribe **la cadena vacía** con un aviso.

O sea que la fila en blanco no es un caso inventado para el test: **es exactamente lo que dejó
aquel fallo en los dieciséis colegios**, y `prematriculas.ts` es quien la vuelve a tocar. *La
premisa mejoró al intentar reproducirla, que es la única forma de saber que una premisa es
cierta.*

---

## 3. El vocabulario: la lista del 46 estaba mal, y rechazar por ella habría roto los dieciséis

El 46 §2 proponía `Falta | Cumple | Observado | Devuelto`. **Ninguno de esos cuatro es lo que
escriben las pantallas desplegadas.**

Y esto no se midió censando una base —hay dieciséis y sólo se ve una—: se midió **contando los
escritores**, que son tres y tienen los tres su lista cerrada en el código fuente.

```
myvc_front/app/scripts/alumnos/personaMatriculasDir.html   'falta' 'ya' 'n/a'   (v1, los 16)
myvc_front/app2/.../persona-matriculas.ts                  'falta' 'ya' 'n/a'   (ESTADOS_REQUISITO)
myvc_front/app2/.../prematriculas.ts                       observacion.estado ?? ''
```

> **Contar los escritores es más fuerte que censar una base:** una base dice qué pasó en un
> colegio, y los escritores dicen **qué puede pasar en los dieciséis**. La copia de desarrollo da
> `12 filas, todas 'falta'` — en minúscula, mientras el defecto del esquema es `'Falta'`. *Ni
> siquiera el defecto del esquema es un valor que alguien escriba.*

**Lo que entra son seis, que es la unión de lo medido**, y vive en `App\Support\EstadosDelPaso`:

```
falta      las tres pantallas   nadie lo ha tocado — REABRE el paso
ya         las dos de ficha     cumplido, el «chulo» de toda la vida
n/a        las dos de ficha     no aplica a este alumno — cierra, y eso es correcto
cumple     la estación          cerrado
observado  la estación          cerrado, con un texto que viaja al siguiente
devuelto   la estación          no pasa — REABRE
```

Y **la cadena vacía no está en la lista**: significa *«no toques la columna»*, que es lo que de
verdad quiere decir quien edita sólo una observación.

### `n/a` cierra, y es la única de las seis que hay que pensar

«No aplica» no es «cumplido». Pero lo que decide la cola de la estación siguiente no es si el papel
llegó: es **si esta familia tiene algo que hacer aquí**. Un requisito que no le aplica no le puede
impedir pasar a la 3. *Hoy se comporta así por accidente; lo que cambia es que ahora está escrito y
hay un test que lo fija.*

---

## 4. El portal: por qué es público y por qué el código solo NO basta

Las tres rutas de la familia son públicas **por el mismo motivo que las cuatro del formulario**:
quien las usa es la familia de un aspirante que todavía no es alumno, **no tiene cuenta y no puede
tenerla**. No hay guard de propiedad que aplicarle a quien no tiene fila en `users`.

**Pero aquí la llave sola no basta, y ésa es la diferencia con sus cuatro hermanas.**

`GET colillas-inscripcion/{codigo}` fijó la regla el 20 sep: **un código no puede revelar el nombre
de un menor**. Se dicta por teléfono y viaja en un papel que pasa de mano en mano, así que aquella
ruta devuelve *el trámite y no la persona*.

Este portal **tiene que devolver la persona**: la familia entra a seguir llenando lo que dejó a
medias, y un formulario que no se puede releer no se puede terminar.

Así que hay **un segundo factor, y es un dato que ya está dentro del formulario**:

```
antes de la primera escritura   el código abre un formulario EN BLANCO
                                -> no hay nada personal que revelar
después                         el código + el documento del aspirante lo reabren
                                -> la llave la eligió la familia, nadie se la dio
```

*La llave no se entrega: la escribe quien la va a usar.* Y si el aspirante no tiene documento
todavía —en preescolar el registro civil llega tarde y el colegio inscribe igual— el segundo factor
es **la fecha de nacimiento**, que la familia siempre sabe y el papel no lleva impreso.

### Lo que esto acepta a cambio, dicho una vez y sin adornarlo

**Quien se encuentre un papel EN BLANCO puede estrenar el formulario.** Es cierto. Y es preferible
a la alternativa —que la familia no pueda entrar—, porque un formulario estrenado por un
desconocido **no matricula a nadie**: gasta un papel que el colegio ya cobró, y secretaría lo ve en
la bandeja con el nombre de un aspirante que no compró nada.

### Y lo fija un test que mira el JSON entero

`test_el_codigo_solo_no_revela_los_datos_del_aspirante` busca el nombre, el documento **y el
teléfono del acudiente** en la respuesta completa, no campo a campo: *un campo de más en una
respuesta pública no rompe nada, no pone nada en rojo y no se nota hasta que importa.*

---

## 5. Lo que NO entra, y ninguna se cae por recorte

| | por qué |
|---|---|
| **`aspirantes.alumno_id`** | El enlace del papel al alumno **ya existe y ya se escribe**: `ordenes_inscripcion.alumno_id`, que ata `PUT informes/formularios-inscripcion/codigo/{codigo}/alumno` (20 sep) con su permiso, su 409 y su test. Una columna aquí sería el mismo dato en dos sitios: o la escriben dos rutas que pueden discrepar, o no la escribe nadie. *Un dato con dueño no se copia: se sigue.* |
| **`requisitos_alumno.aspirante_id`** | El 46 la descartó *«hasta que exista quien llene el hueco»*. **Hoy existe** —`aspirantes` tiene nombre—, pero sigue fuera: es un `ALTER` que reescribe la tabla para anular una clave ajena en MariaDB 10.5, y **ninguna pantalla pide todavía el recorrido de un aspirante**. Ya no está descartada: está esperando a su primera pantalla. |
| **Crear el alumno al admitir** | `matriculas` tiene **ocho escritores** en `app/` y ninguno sería éste; una novena forma de abrir una matrícula es exactamente cómo aparecieron los huérfanos que `MatriculasHuerfanas` va a buscar. Y matricular **ya es la pantalla 14**, con sus rutas. Admitir y matricular tienen días de por medio. |
| **El informe de campaña dentro del tablero** | Ya es `GET informes/formularios-inscripcion/campana`, con `sin_volver` dentro. Duplicarlo serían dos cifras que algún día se contradicen — el fallo que aquel documento ya deja avisado en su propio cuerpo. |
| **Una ruta para resolver la cita** | Agendarla y resolverla son **una escritura sobre la misma fila**. Es el caso de `accesos-favoritos`, que previó tres rutas y entregó dos. **Quedarse corto respecto a lo autorizado se cuenta y se dice; no se rellena para cuadrar.** |
| **La firma y la pasarela** | **Ninguna de las dos está bloqueada: las dos están RETIRADAS**, por dos respuestas de Joseth del 20 sep. La fase 4 se queda sin contenido y desaparece. Ver la §9. |

---

## 6. El permiso: nueve con `auth.personal` y **una** con el candado dentro

Las nueve van con `auth.personal` y nada dentro, que es la decisión de Joseth del 20 sep para todo
el día de matrículas: *«cualquiera del personal puede cerrar, pero queda con su nombre y su hora»*.

**La excepción es `PUT aspirantes/{id}/decision`**, con `Autoriza::puedeDecidirAdmision`. Y no es
simetría rota:

> Admitir **no es un paso**. Un paso es reversible, lo ve la familia y lo corrige el de al lado.
> Admitir es **la respuesta del colegio a una familia**, se dice una vez y se dice fuera: un «no
> admitido» escrito por equivocación viaja al portal y lo lee la madre antes de que nadie se
> entere. `auth.personal` deja pasar a las 75 cuentas de personal, de las que **53 son docentes**,
> y ninguno de ellos admite a nadie en ningún colegio.

### Y el coordinador académico entra — Joseth, 20 sep 2026

Textual: *«el coordinador académico puede admitir estudiantes también.»* Con eso
`puedeDecidirAdmision` **deja de ser `esAdministrativo()`** y pasa a la forma de
`puedeCambiarLaNotaNumerica`: lista de roles cruzada contra `Role::getUserRoles()` en **una sola
consulta**.

**Contado por `role_id` y no por nombre** —la tilde de `Coord académico` hace que un `WHERE
r.name IN (…)` desde el cliente `mysql` devuelva cero filas teniendo titular, que es el
[33](33-la-tilde-que-sql-no-ve.md)—, remedido en la copia de desarrollo el 20 sep:

```
rol  1  Admin              10 titulares, los 10 superusuarios
rol  9  Coord académico     1 titular,  NO superusuario   <- el que entra
rol 10  Rector              0
rol 12  Secretario          0

quien admitía (superusuario o Secretario) ......... 12
con `Coord académico` dentro ...................... 13   de 75 de personal
```

O sea que **añade a una persona de verdad**. `Rector` **no se mete de paso** aunque la pregunta
de abajo lo nombre: Joseth nombró un rol, y meterlo sería [[crear-rol-no-regala-permisos]] al
revés — además de inerte, con cero titulares. Lo fija un test que se pondrá rojo el día que se
cuele.

**Y sigue medio provisional, dicho aquí para que no se lea como cerrado**:
`INVESTIGACION-MATRICULAS.md` §10.4 pregunta *«¿quién admite en un colegio típico: el rector solo,
o un comité?»*, y esta respuesta contesta **quién más**, no **cómo**. La forma de lista hace que
el día que conteste sea una línea y no un rediseño.

---

## 7. Lo que la construcción destapó y ningún plan preveía

### 7.1 · El candado del nombre del método volvió a hablar, y tenía razón

`AutorizacionTest` agrupa las rutas **por el nombre del método** y delató `PUT inscripcion/{codigo}`
como *«4 de 5 con guard»* — la forma exacta de un agujero, porque el método se llamaba `putIndex` y
sus cuatro hermanas de `@putIndex` sí llevan guard.

**El arreglo no fue declararla como excepción: fue llamarla `putFormulario`, que es lo que hace.**
Es literalmente lo mismo que le pasó a `postIndex` → `postAcunar` el 19 sep en la familia de al
lado. *Un candado de consistencia diciendo la verdad sobre un nombre, por segunda vez en dos días.*

### 7.2 · El aviso se perdía en el borde del segundo, y lo destapó el test

La fuente nueva de `notificaciones:enviar` marca por **sello de tiempo** y no por `id`, porque
`requisitos_alumno` se rellena de forma perezosa y **después se actualiza**: su `id` no se mueve
cuando una estación cierra el paso.

Y `timestamp` tiene precisión de **segundo**, así que un paso cerrado en el mismo segundo en que
corrió la pasada anterior cae justo en la marca. Con `>` **se pierde para siempre**.

Va con `>=`, que repite **una vez** los de ese segundo exacto — que es exactamente lo que la
cabecera de esa clase ya decidía para el otro borde: *«un aviso repetido es una molestia y uno
perdido es la función sin cumplir»*. **Es la misma medicina que el 46 §4 le recetó a la huella de
las estaciones**, y por el mismo motivo: *cuando el reloj no tiene resolución suficiente, se elige
de qué lado se falla.*

Lo destapó el test, que corre entero dentro de un segundo y por tanto **vive siempre en ese borde**.

### 7.3 · El aviso obliga a un cuarto tipo de tema, y eso mueve un contrato

`TemasDeNotificacion::TIPOS` pasa de tres a cuatro con `matricula`, así que `GET
notificaciones/temas` devuelve **cuatro claves en vez de tres**.

**Tiene que ser un tipo propio**, y no por orden: lo que un tipo significa ahí es *«un interruptor
que la familia puede apagar por separado»*. Metido dentro de `disciplina`, apagar las situaciones
apagaría el aviso de que la devolvieron en Documentos. Son cinco avisos en una mañana de sábado una
vez al año contra un goteo de todo el curso: nadie los quiere con el mismo interruptor.

**Una app vieja no se apunta al tema nuevo y simplemente no recibe estos avisos**, que es degradarse
bien. Hoy además no hay ninguna que pueda: `myvc_flutter` sigue sin `firebase_messaging`.

### 7.4 · UN CONTROL QUE NO SE PUSO ROJO, y lo que costó arreglarlo bien

Al romper `sinTerminar()` quitándole el marcador `tocado`, **el test que parecía cubrirlo siguió en
verde**. El motivo: aquel test separa al que vino del que no, y al que no vino le falta la fila
entera, así que el marcador no participa.

Lo que `tocado` protege de verdad es el caso contrario: **a quien le devuelven un paso no le queda
nada cerrado** —devolver limpia `cerrado_at` a propósito— y aun así sigue en el patio. Sin el
marcador, esa familia **desaparece de todas las listas justo cuando más falta hace verla**, que es
la misma desaparición silenciosa que el 46 §2 encontró en la cola de la primera estación.

Se escribió el test que nombra ese caso, y con él el control **sí** se pone rojo, y sólo él.

> *Un control no vale por existir: vale por haberse visto en rojo, y por que el rojo sea el que se
> esperaba.* Es la tercera vez que este repo lo escribe y la primera en que lo encuentra **por no
> haberse puesto rojo**, en vez de por ponerse.

---

### 7.5 · Las dos rutas que se diferencian en tres caracteres, y el error que NO hace ruido

`requisitos/recorrido/{alumno_id}` (el personal, `auth.personal`) y
`requisitos/mi-recorrido/{alumno_id}` (la familia, `boletin.propio:sin-paz-y-salvo`) son para
públicos opuestos. Lo levantó `myvc-flutter-1a` —*«eso se va a confundir solo»*— y **el nombre se
queda**: `disciplina/mis-fichas/{alumno_id?}` ya usa ese prefijo para exactamente lo mismo desde
antes, así que renombrar rompería un idioma que un lector de `routes/` ya decodifica sin pensar.

**Lo que sí quedó, porque el riesgo es real y va al revés de como se propuso:**

```
familia  -> requisitos/recorrido      403 SIEMPRE            se ve
personal -> requisitos/mi-recorrido   200 SIEMPRE, con dos   no se ve
                                      campos de menos
```

El primero es ruidoso y seguro. El segundo no falla nunca: `ExigirBoletinPropio` **deja pasar de
largo a todo el personal** —a propósito, para que secretaría le pueda enseñar el recorrido a una
madre por teléfono—, así que una pantalla del personal mal cableada devuelve la vista de la
familia **sin la observación interna y sin quién cerró cada paso**. *El síntoma no es un error,
son dos campos que faltan, y eso se descubre el día que alguien en el patio pregunta por qué no
ve la observación.* Lo fija un test.

> **Y la propuesta de test que llegó habría fijado lo contrario.** Decía *«que `mi-recorrido` no
> traiga `descripcion` ni `cerrado_por_nombres`»*, y **`descripcion` sí viaja**: es la del
> **requisito**, que es pública y es lo que le dice a la familia qué le piden. La que no viaja es
> `ra.descripcion`, que sale con el alias `observacion`. *Dos columnas que se llaman igual en dos
> tablas.* El test fija las tres cosas: sin `observacion`, sin `cerrado_por`, **con**
> `descripcion`.

> **El riesgo que se propuso primero —un «docente-padre» que pasaría los dos guards— se midió y
> no se sostiene aquí:** **0** cuentas de personal tienen ficha de acudiente; los 1.000 que la
> tienen son `tipo=Acudiente`, y un docente con hijo matriculado tiene **dos cuentas**, no una con
> dos capacidades. *Con su condición de caducidad al lado: eso es la copia de desarrollo, y en los
> otros quince no ha mirado nadie. Lo que protege esa línea no es una regla del código — es que
> ningún colegio le haya puesto todavía ficha de acudiente a una cuenta de personal.*

### 7.6 · «Sin desplegar» describe dos estados distintos, y el repo que lo lee no sabe cuál le tocó

`myvc_flutter/docs/backend-pendiente.md` llegó a tener **el mismo rótulo** —*«ESCRITOS el 20 sep,
sin desplegar»*— para dos cosas que no son lo mismo: las nueve de `estaciones/*`, **fundidas en
`main`**, y `mi-recorrido` con el cuarto tema, **en una rama sin fundir**. Con ese rótulo el lado
Flutter construye contra una ruta que no existiría **ni desplegando `main`**. Lo cazó
`myvc-flutter-1a` con `git grep mi-recorrido main`, que no devolvió nada.

*Es «el router está en N» sin decir el árbol, otra vez, y en el documento que cruza los dos
repositorios.* **El estado de una ruta se escribe con el dónde delante**: «fundida en `main`», «en
una rama», «desplegada en los dieciséis». Nunca sólo con el cuándo.

### 7.7 · 🔴 «CON SU NOMBRE Y SU HORA» — y el nombre sale VACÍO justo para quien atiende

Lo destapó el test de la §7.5 al escribirlo, y **no es de esta tanda: está en `main` desde el
20 sep**, en `getRecorrido`.

Toda la decisión de Joseth sobre el día de matrículas es *«cerrar un paso lo puede hacer
cualquiera del personal, **con su nombre y su hora**»*. La hora sale. El nombre sale de
`profesores`:

```sql
LEFT JOIN profesores p ON p.user_id = u.id
   -- p.nombres AS cerrado_por_nombres, p.apellidos AS cerrado_por_apellidos
```

**Y 0 de las 22 cuentas de tipo `Usuario` tienen ficha en `profesores`** —las 47 que la tienen
son docentes—, medido en la copia de desarrollo y **0 de 20 en la base de tests**. O sea que
cuando cierra el paso un administrativo, `cerrado_por` viaja con su id y `cerrado_por_nombres`
viaja **en `NULL`**. Es exactamente la trampa que el `CLAUDE.md` ya tiene escrita —*«cualquier
consulta que saque el nombre de una persona uniendo sólo contra `profesores` deja sin nombre justo
a secretaría»*—, cometida de nuevo, y esta vez **en el módulo cuyo argumento entero es que quede
el nombre**.

**No se arregla aquí, y no por pereza: no hay de dónde sacar el nombre.** `users` tiene
`username` y ninguna columna de nombre —comprobado en `information_schema`—, así que el arreglo
es elegir qué se enseña cuando no hay ficha, y eso es producto: *«ADRIANA GÓMEZ»* y
*«secretaria2»* no se leen igual en una pantalla que dice quién cerró el paso. **Espera a
Joseth.** Mientras tanto el test lo fija **en el estado en que está**, con la línea que hay que
cambiar señalada — para que el día que se arregle salga en rojo en vez de pasar inadvertido.

---

## 8. Lo que mueve, contado y no supuesto

**Cuatro instantáneas**, y el diff de las cuatro se miró entero en vez de regenerar y pasar:

| | qué se movió |
|---|---|
| `rutas.json` | **634 → 644**, las diez y ninguna más; ninguna cambió de acción |
| `guards-por-ruta.json` | 6 a `auth.personal` + 1 a `boletin.propio [sin-paz-y-salvo]`. **Las tres públicas no entran**: ese censo lista las que llevan guard |
| `guard-por-familia.json` | `aspirantes: 5 de 5` · `inscripcion: 3 de 0` · `estaciones: 9→10` · `requisitos: 7→8` |
| `familias-que-nunca-entran-en-el-candado.json` | **una línea: `inscripcion: 0 de 3`** |
| `escrituras-donde-el-candado-no-llega.json` | **26 → 28**, y la diferencia de conjuntos son exactamente las dos del portal |

Y **tres listas de tests**: `AutenticacionTest::SIN_GUARD` (+3, con el motivo),
`RutasPreLoginTest::TOTAL_PUBLICAS` (**16 → 19**) y
`FamiliasQueNuncaEntranTest` (**26 → 28**).

### El `0 de 3` es la forma exacta de un agujero, y se acepta con el motivo

Nunca metiendo la familia en la lista de exclusiones, que era el atajo que había a mano. Lo que ese
candado pregunta es *«¿algún mecanismo comprueba de quién es la fila que toca?»*, y aquí la
respuesta es **sí, y son tres, y ninguno es un middleware**: el carácter de control del código, el
segundo factor, y el tope por fila —un aspirante por orden, un documento pendiente por requisito—.

**Es el cuarto motivo distinto seguido en este contador y el más cercano a lo que busca**, porque a
diferencia de `pagos-inscripcion` **estas dos escriben datos personales de un menor**. El día que
acepten un `aspirante_id` suelto en el cuerpo seguirán contando 28 y ya serán un agujero: *el
número no es la garantía, el porqué de cada renglón sí.*

---

## 9. Lo que sigue abierto

### ~~¿Qué pasarela tiene contratada cada colegio?~~ — CONTESTADA Y RETIRADA

**Joseth, 20 sep 2026:** *«cada colegio maneja su propio sistema de cartera y
contabilidad, unos ni tienen pagos en línea. Eso no importa.»*

Esta pregunta llevaba abierta desde `INVESTIGACION-MATRICULAS.md` §10.2 y se ha estado
citando como *«bloquea la fase 4 entera»*. **No bloquea nada**, y de paso aclara qué era:
la «pasarela» de este módulo es **sólo la del formulario de inscripción** —el checkout de
`pagos-inscripcion/{codigo}`, del 19 sep—, para que una familia pague **el papel** con
tarjeta en vez de llevar la colilla al banco. Nunca fue de pensiones ni de matrícula. Y ya
está construida de forma que **el colegio sin credenciales contesta 404**, así que el que
no usa pagos en línea no ve nada (doc 40 §5, regla 1: *el back no publica lo que está
apagado*).

#### Y la consecuencia que sí importa: LA PANTALLA 12 NO SE PUEDE CONSTRUIR AQUÍ

`PANTALLAS-MATRICULA.md` promete de la **Estación 5 · Tesorería**: *«estado de cuenta de
la familia entera, descuento de segundo hijo aplicado por la regla, plan de pensiones y
cobro»*.

**Nada de eso sale de esta base.** Medido en `database/schema/mysql-schema.sql`: **no hay
ni una tabla** de cartera, pagos, saldos, recibos, facturas ni pensiones. Lo único que
esta API sabe de deuda es `alumnos.pazysalvo`, un `tinyint(1)`.

Eso no es un hueco que haya que tapar: es que **la contabilidad vive fuera, en el sistema
de cada colegio**, que es exactamente lo que él acaba de decir. Lo que MYVC hace en esa
estación es lo mismo que en las otras —cerrar el paso con su nombre y su hora, y el globo
de notas—, y el estado de cuenta lo mira el tesorero donde lo mira hoy.

*Se dice para que el front no construya esa pantalla contra un dato que no existe.*

---

### ~~¿El contrato y el pagaré se quedan en papel?~~ — CONTESTADA, Y LA PANTALLA 13 SE RETIRA

**Joseth, 20 sep 2026:** *«No entiendo lo del pagaré en papel. Nosotros solo le damos opciones
para que mande la colilla por foto o el número de recibo y le mandamos la notificación al
tesorero, él verifica, acepta y con eso le llega el formulario al solicitante. No me importa si
después le piden la colilla en físico.»*

**Con eso se cae la pantalla 13 entera y con ella la «fase 4», que se queda sin contenido.** El
contrato con firma electrónica, el pagaré, la huella del PDF, la IP y la Ley 527 salieron de
`myvc_front/INVESTIGACION-MATRICULAS.md` §4 —una investigación de *qué hacen los buenos*— y **no
de un requisito suyo**: no reconoce eso como algo que su producto haga. *Una pregunta que llevaba
días citada como «bloqueante» estaba bloqueando una pantalla que nadie había pedido.* Es la
segunda de las dos que se retiraron el mismo día, y las dos por lo mismo.

#### Y su frase describe un flujo que YA EXISTE salvo una pieza

| lo que él describe | qué hay |
|---|---|
| «que mande la colilla por foto **o el número de recibo**» | `POST colillas-inscripcion/{codigo}` — acepta fichero **o** referencia |
| «**le mandamos la notificación al tesorero**» | **NO EXISTE, en ningún nivel.** Ver abajo |
| «él verifica, acepta» | `PUT colillas-inscripcion/{id}/aprobar` · `…/rechazar` |
| «y con eso le llega el formulario al solicitante» | la orden pasa a `PAGADA`, y con eso `PUT inscripcion/{codigo}` ya deja llenar el formulario (esta tanda) |

### EL AVISO AL TESORERO: medido antes de prometerlo, y NO es «una fuente más del cron»

Es el único hueco de lo que él da por hecho, y lleva desde el 19 sep escrito como abierto en el
[41](41-el-formulario-de-inscripcion.md) §7. **Se midió en vez de estimarlo, y lo que aparecieron
fueron tres faltas encadenadas, no una** — medido el 20 sep 2026:

| | medido | con qué |
|---|---|---|
| **1 · No hay ningún tema de PERSONAL** | los temas son **4 por alumno** (`TemasDeNotificacion::TIPOS`) más **2 de colegio** (`DEL_COLEGIO`). Ninguno se dirige a una persona del colegio | leyendo la clase |
| **2 · La app no puede recibir push** | `myvc_flutter/pubspec.yaml` trae `firebase_core` y `firebase_analytics` y **no `firebase_messaging`**; `subscribeToTopic` aparece **0 veces** en código —los 4 aciertos son docblocks que dicen justamente que no está— | `grep` en el otro repositorio |
| **3 · No hay a quién avisar** | `years.tesorero_id` está **en NULL en los 9 años vivos** de la copia de desarrollo, y su único lector es `Autoriza::puedeAprobarColilla`, que por eso lleva el respaldo de secretaría | consulta a la base |

**El nombre del tema es la única puerta de este diseño** —se deriva con HMAC del **id del alumno**,
y eso es lo que impide apuntarse al de otro—. Un aviso al tesorero no tiene alumno: tiene
**persona**, y eso es un tipo de tema que hoy no existe. O sea que la pieza que falta no es una
consulta más en `EnviarNotificaciones`: es **un tema nuevo, una app que añada el plugin y se
suscriba, y un colegio que nombre a su tesorero** —lo tercero ya tiene pantalla, `colegio-ficha`
de `app2`, así que es una elección suya y no un hueco—.

> **Y la bandeja tampoco la abre nadie todavía, que es el dato que cambia la urgencia.**
> `GET colillas-inscripcion/pendientes` existe desde el 19 sep y **ningún cliente la llama**:
> `colillas-inscripcion` aparece **0 veces** en `myvc_front`, `myvc_front_2` y `myvc_flutter`.
> *Conectar el aviso a una bandeja que no tiene pantalla sería avisar de algo que no se puede ir a
> mirar.* El orden barato es el contrario: primero la pantalla, y el aviso cuando haya push.

Se le pusieron las tres salidas delante —tema de persona, correo, o contador en la bandeja— y
**eligió una cuarta que ninguna de las tres había mirado**, el 20 sep 2026:

> *«actualmente los tesoreros tienen cuenta administradora, al entrar les aparece que hay cambios
> solicitados por algunas personas, tal vez podamos reutilizar eso y agregar esta notificación ahí
> para que le llegue ahí cuando recargue la página y apruebe.»*

**Y es mejor que las tres, porque no necesita push ni correo ni pantalla nueva.** Eso que él
describe existe y tiene nombre: **`GET ChangesAsked/to-me`**, que pinta
`app2/paginas/panel/peticiones` y que **ya es un agregador heterogéneo** —cambios pedidos por
alumnos, solicitudes de asignatura de los docentes, historial de sesiones, intentos de login
fallidos, publicaciones, eventos y el horario de hoy—. Una colilla pendiente sería **un bloque
más** en una respuesta que ya junta siete cosas que no se parecen entre sí.

### Pero medido antes de prometerlo, otra vez — y aparecieron dos cosas

**1 · `getToMe` tiene cinco ramas por `tipo`, y una de ellas no devuelve ninguna bandeja.**

| rama | qué recibe | cuántos |
|---|---|---|
| `Usuario` **y** `is_superuser` | la bandeja entera | **11** |
| `Profesor` | la de su grupo, si es titular | 53 |
| `Usuario` **sin** superusuario | sólo `publicaciones` y `eventos` — **ninguna petición** | **11** |

O sea que *«los tesoreros tienen cuenta administradora»* **no basta**: de las 22 cuentas de tipo
`Usuario`, **la mitad no ve hoy ninguna bandeja**. El bloque tiene que colgar del criterio que ya
decide quién aprueba —`Autoriza::puedeAprobarColilla`— **y no de la rama del `switch`**, o el
tesorero que no sea superusuario no lo vería nunca y nadie sabría por qué.

**2 · Y un tesorero con «cuenta administradora» NO puede ser nombrado tesorero.** `years.tesorero_id`
es un `profesores.id`, y **0 de las 22 cuentas de tipo `Usuario` tienen ficha en `profesores`**
—las 47 que la tienen son docentes—. Así que la columna sólo admite a un docente, y el flujo de
hoy funciona **por el respaldo** (`esAdministrativo`), no por ella. Con `tesorero_id` en NULL en
los 9 años vivos, eso significa que quien aprueba hoy es **secretaría o superusuario**, que es
exactamente lo que él llama «cuenta administradora». *La pieza no está rota; está apoyada en el
respaldo, y eso conviene saberlo antes de construir encima.*

### Lo que costaría, para que se decida con el precio delante

`ChangeAskedController` es legado de 1.400 líneas con tabuladores, y `GET ChangesAsked/to-me`
**lo leen los tres clientes**: añadir una clave a su respuesta es un cambio de contrato, aunque
sea aditivo. No entra en esta tanda, que ya está cerrada; **es la primera de la siguiente**, y con
esto medido ya no hay nada que investigar para escribirla.

| | quién |
|---|---|
| ~~¿El contrato y el pagaré se quedan en papel?~~ | **CONTESTADA el 20 sep**: no hay pagaré. La pantalla 13 y la fase 4 se retiran (arriba) |
| **¿Por qué canal se avisa al tesorero?** | **Joseth** — tres salidas medidas arriba, y ninguna se escribe hasta que elija |
| **¿Quién admite: el rector solo o un comité?** | **Joseth** — contestada a medias: el coordinador académico entra (§6), la forma no |
| Correr `tools/requisitos-de-matricula.php` en `lal` | **Joseth** — sigue sin correrse desde el 20 sep; dice qué se encuentra un colegio al desplegar |
| Las pantallas 02, 04, 05, 06 y 15 | **`myvc_front`** — el backend está, y sin ellas no lo usa nadie |
| Las doce pantallas de estaciones | **`myvc_flutter`** |
| Llevar el `CON_COMPROMISO` de la cita al observador | de otra tanda. Aquí se garantiza que el dato exista y sea consultable, **no que aparezca en febrero en la planilla del docente** |
| El vocabulario de `estado` **migrado** en los dieciséis | sigue pendiente: lo que se paga hoy es que **ninguna ruta pueda escribir fuera de la lista**, no que lo escrito antes se normalice |
