# Los certificados de estudio, empezando por el membrete

Primera entrega sobre `routes/api/informes.php`, que es el fichero de **lo que el
colegio imprime y firma**. La cobertura del 21 de agosto de 2026 daba **364 de
539 rutas (67%)**, y dentro de informes el hueco mayor era
`ConfigCertificadosController`: **1 de 6**.

Fijado por `tests/Contrato/ConfigCertificadosTest.php` (9 casos) y
`tests/Contrato/FrasesPreescolarTest.php` (9) y `tests/Contrato/HistorialesTest.php`
(5). Salvo la §3, ninguno exige lo correcto: fijan lo que hace hoy.

Lo que se configura aquí no es el dato de un alumno, es **el membrete**: qué
imagen va de encabezado y de pie de página con sus márgenes
(`config_certificados`), y el texto de cabecera del año
(`years.encabezado_certificado`). Un certificado de estudio es un documento que
el colegio entrega firmado, así que **lo que se rompa aquí sale impreso**.

Las seis rutas llevan `auth.personal`, o sea los 51 profesores.

---

## §1. Un año puede quedar apuntando a un membrete que no existe

`PUT certificados/actual` coge `config_certificado_estudio_id` del cuerpo y lo
escribe en `years` **sin comprobar que exista**. `years` no lleva clave foránea
para esa columna, así que nada lo para: la fila queda apuntando al vacío y la
respuesta es `"Cambiado"`.

**Lo que hace que importe es cuándo se nota.** No se nota aquí. Se nota el día
que alguien va a imprimir un certificado —que es justo el día en que no se puede
esperar—, y para entonces el rastro de quién lo cambió y cuándo se ha perdido.

Es la forma contraria a la de
[13-actividades.md §4](13-actividades.md): allí la integridad la sostenía el
esquema porque la tabla sí llevaba `FOREIGN KEY`, y se anotó por ser lo raro en
este repo. Aquí no la lleva, y se ve la diferencia.

## §2. Y se cambia el membrete de cualquier año, no solo del suyo

`putActual()` y `putEncabezado()` reciben `year_id` **por el cuerpo**, hacen
`Year::find()` y guardan. Nadie mira si ese año es el del usuario.

Cualquiera de los 51 profesores reescribe la cabecera de los certificados de un
año que no es el suyo, **incluidos los años cerrados** — de los que se siguen
pidiendo certificados de estudio viejos, que es el caso de uso entero de esta
pantalla.

Se junta con la pregunta que la [09 §5](09-pendientes.md) tiene abierta para las
44 rutas de estructura: quién configura el colegio. **No se cierra por separado.**

## §3. El barrido de los `find()` sin `OrFail` — hecho el 21 ago 2026

`putUpdate`, `putActual`, `putEncabezado` y `deleteDestroy` resolvían con
`find()` y seguían escribiendo propiedades sobre el `null` que devuelve. En PHP 8
eso es fatal: **500 donde tocaba 404**, cuatro veces en un controlador de seis
métodos.

Joseth decidió hacerlo como barrido y no como arreglos sueltos.

### §3.1. La medición, que tuvo que hacerse dos veces

| Criterio | Sitios |
|---|---|
| `Modelo::find()` sin `OrFail`, ventana de 4 líneas buscando `if (!$x)` o `=== null` | **47** |
| lo mismo, aceptando también `if ($x)`, `empty()`, `is_null()`, `??` | **37** |

Los diez de diferencia **sí comprobaban el nulo**, en la forma positiva
—`$alumno = Alumno::find($id); if ($alumno) {`— que el primer patrón no veía.

Otra sesión midió lo mismo por su cuenta y le salieron **20** y luego **10**, con
un criterio más estrecho —«el resultado se usa en la línea siguiente como
objeto»—. Ése tiene el problema contrario: **se deja fuera casos reales**, entre
ellos los cuatro de este controlador, donde entre el `find()` y el uso hay una
línea en blanco y un `if` que no toca la variable. Estaban probados con test
antes de tocarlos.

Cuatro medidas del mismo patrón —**47, 37, 20, 10**— y **ninguna es la buena**:
las anchas tienen recall alto y precisión baja, la estrecha al revés. Lo único
que lo resolvió fue **leer cada sitio y escribir el test**.

Y un detalle que lo cierra: las dos sesiones fallamos por separado en la misma
variante, **la comprobación repartida entre dos variables** — una en forma
positiva (`if ($alumno)`) y otra en orden (`if (!$rol || !$per)`, con la variable
buscada en segundo lugar). Que dos criterios distintos tropiecen con el mismo
hueco no es casualidad: **«hay comprobación» tiene más formas de las que uno
enumera de memoria**, y por eso el patrón sirve para hacer la lista y nunca para
cerrarla.

**Es el mismo error que la §6 de [13-actividades.md](13-actividades.md)**, cometido
el mismo día y a sabiendas: un patrón con un límite arbitrario dentro que infla la
lista, y el límite no se ve porque lo que deja fuera no aparece en ninguna parte.
Allí fue una ventana de 25 líneas; aquí, no contemplar `if ($x)`. Se descubrió
mirando los primeros cinco antes de tocarlos, que es lo único que lo caza.

De los 37, **once eran de este frente** y son los que se arreglaron. Los otros 26
están medidos y repartidos entre las sesiones que llevan esos ficheros.

### §3.2. Por qué no es cosmética

Un 404 en vez de un 403 no gana nada y no se toca —eso ya se decidió—. Pero
**un 500 no es una elección de código: es un proceso que revienta**, y con
`APP_DEBUG` encendido **devuelve la traza al cliente**, que es justo lo que la
[01](01-plan-seguridad.md) tiene pendiente de comprobar colegio a colegio. Además
ensucia el log donde luego hay que buscar los fallos de verdad.

## §4. Dos cosas que NO son fallos, y por eso se escriben

Las dos se parecen mucho a los fallos que esta serie lleva encontrando, y la
diferencia solo se ve preguntando o mirando el cliente. Escribirlas es lo que
evita que alguien las «arregle».

**`postStore()` lee `encabezado_img_id` y `putUpdate()` lee `encabezado_img`.**
Parece el descuido clásico de dos nombres para lo mismo. Se fue a mirar el front:
`configCertificados.html` liga el formulario de crear a
`newcertif.encabezado_img_id` y el de editar a `currentCertif.encabezado_img`.
**Son dos formularios distintos de verdad**, y la API espeja al cliente. Feo y
correcto.

**Editar sin mandar la imagen la borra**, con un `else` explícito que escribe
`null`. Se parece a [13-actividades.md §1](13-actividades.md) —donde el `null`
llega por omisión y nadie lo quiso— pero aquí **el borrado es la función**: es
como se vacía el membrete desde la pantalla. Quien venga a arreglar «los campos
que se borran al guardar» tiene que saber que en este método quitarlo dejaría el
membrete sin poderse vaciar.

Las dos formas se parecen tanto que se confunden, y por eso cada una tiene su
test con el porqué dentro.

## §5. Una columna más que no guarda lo que dice

`putUpdate()` asigna `$certif->created_by = $user->user_id`. La columna se llama
«creado por» y guarda «editado por»: **el rastro de quién creó el membrete se
pierde en la primera edición**.

Es menor, y va anotada porque es la tercera de esta serie:

| Columna | Qué guarda de verdad |
|---|---|
| `ws_actividades.created_by` | el `persona_id`, no el `users.id` |
| `ws_preguntas.added_by` | el `user_id` |
| `config_certificados.created_by` | quién editó el último |

Tres columnas de propiedad, tres significados, nombres igual de genéricos. **El
día que se escriba un guard de propiedad hay que mirar la columna, no fiarse del
nombre** — está razonado en [13-actividades.md §2](13-actividades.md), donde
comparar contra lo que uno esperaría daría un `WHERE` que no casa nunca.

---

## §6. Dos interruptores del membrete que no lee nadie

Medido con `tools/interruptores-que-nadie-lee.py`, cruzando el backend con los
tres clientes:

```
encabezado_solo_primera_pagina    <-- NADIE, en ningún cliente
piepagina_solo_ultima_pagina      <-- NADIE, en ningún cliente
```

`postStore()` y `putUpdate()` las escriben —con `Request::input(..., 0)`, o sea
que además tienen valor por defecto—, el índice las sirve, y **ningún `if` las
mira**: ni aquí, ni en `myvc_front`, ni en `myvc_front_2`, ni en `myvc_flutter`.

Lo que quieren decir por su nombre es que el encabezado salga solo en la primera
página y el pie solo en la última, que es una petición normalísima en un
certificado de varias páginas. **No lo hacen.**

**Y la segunda pregunta es la que decide si importa:** ¿las ha pulsado alguien?
En la copia de desarrollo, `config_certificados` tiene **una fila y las dos a 0**,
así que aquí no ha pasado nada. En un colegio con varios membretes puede que sí,
y eso no se puede contestar desde aquí — se contesta con la misma orden contra
las dieciséis bases.

Si la pantalla de configuración ofrece las dos casillas, entonces hoy el colegio
las marca y no pasa nada: es la forma de «una respuesta que miente» sin
respuesta, un interruptor que se guarda y no gobierna. Si no las ofrece, es
esquema muerto y se borra el día que se toque la tabla.

---

## §7. Las frases del boletín de preescolar

Segundo hueco del fichero: `BolfinalesPreescolarController` estaba a **2 de 5**, y
las tres sin comprobar eran justo las que escriben. Fijado por
`tests/Contrato/FrasesPreescolarTest.php` (7 casos).

En preescolar el boletín no lleva notas, lleva **frases**: el texto que sale
impreso en el informe de un niño de cinco años, redactado por el profesor y por
asignatura.

### §7.1. «Cambiada» y «ELIMINADA» sin haber tocado nada

`putGuardarFrase()` hace un `UPDATE ... WHERE id=?` y devuelve la cadena
`'Cambiada'` pase lo que pase. `putEliminarFrase()` hace lo mismo con
`'ELIMINADA'`. Con un id que no existe, MySQL toca cero filas y el cliente
recibe un 200 diciendo que sí.

Es la familia de «una respuesta que miente», **pero por una vía que
`tools/respuestas-que-mienten.py` no mira**: ese detector busca un `if` de
permiso que envuelve el método sin salida, y aquí **no hay ningún `if`**. No es
que falte una comprobación y el método siga: es que **nadie mira el resultado de
la escritura**. `DB::update()` y `DB::delete()` devuelven el número de filas
afectadas, y las dos veces se descarta.

Merece la pena separarlas porque el arreglo es distinto: la del detector se
arregla poniendo el `else`; ésta se arregla **leyendo lo que ya devuelve la
función que se está llamando**.

### §7.2. El borrado es físico, y esta tabla no tiene papelera

`DELETE FROM frases_preescolar WHERE id=?`. Y `deleted_at` **no existe en el
esquema**, así que no es un descuido del controlador sino de la tabla: se diseñó
sin papelera.

En este proyecto casi todo lo demás va a la papelera, y lo que se borra aquí es
texto que escribió un profesor. Quien pulse «eliminar» por error no tiene de
dónde recuperarlo, **y el resto de las pantallas del sistema le han enseñado que
sí lo tendría**.

No se cambia: añadir `deleted_at` es una migración y una decisión. Lo que hacía
falta es que estuviera escrito.

### §7.3. Guardar mueve la frase a la asignatura que diga el cuerpo

`putGuardarFrase()` escribe `asignatura_id` además de la definición, así que la
misma llamada que corrige una errata puede reasignar la frase a otra asignatura
—la de otro profesor, otro grupo u otro año—. Nadie comprueba de quién es ninguna
de las dos. Va con la [§2 de 13-actividades.md](13-actividades.md).

### §7.4. Y un contraste que vale por los dos

`frases_preescolar` **sí** lleva `FOREIGN KEY` a `asignaturas`, así que crear con
una asignatura inexistente da 500: el controlador no comprueba nada y quien dice
que no es la base.

Justo al lado, la §1 de este mismo documento: `years` **no** lleva la clave para
`config_certificado_estudio_id`, y por eso un año puede quedar apuntando a un
membrete que no existe.

**Dos tablas del mismo módulo, una con red y otra sin ella, y el código se
comporta igual de confiado en las dos.** Es la mejor razón que ha aparecido hoy
para no dar por buena una validación solo porque «hasta ahora no ha fallado».

### §7.5. `updated_by` no la escribe nadie

La columna existe y los tres métodos resuelven el usuario sin usarla. Del texto
que sale impreso en el boletín de un niño **no queda registro de quién lo
escribió**.

Con ésta van cuatro columnas de propiedad en la serie, y es la primera que no
guarda nada en absoluto:

| Columna | Qué guarda de verdad |
|---|---|
| `ws_actividades.created_by` | el `persona_id`, no el `users.id` |
| `ws_preguntas.added_by` | el `user_id` |
| `config_certificados.created_by` | quién editó el último |
| `frases_preescolar.updated_by` | **nada** |

---

## §8. Y una ruta que no funciona para nadie, encontrada al barrer

`PUT preguntas/edicion` —la pantalla de editar una pregunta de un examen—
responde **500 con cualquier id, exista la pregunta o no**. Comprobado con una
pregunta real.

No es el `[0]` sobre una consulta vacía, que era lo que se venía a arreglar. Es
que el `ORDER BY` dice **`p.order`** y la columna se llama **`orden`**, así que la
consulta falla antes de devolver nada. **La pantalla no funciona en ningún colegio
y no ha funcionado nunca.**

**Y lo que enseña es sobre el propio barrido.** Se había escrito ya el
`?? abort(404, 'Esa pregunta no existe')` —correcto, coherente con los otros tres
del mismo controlador— y hubo que retirarlo al medirlo: era **código
inalcanzable**, porque la excepción de SQL salta antes. Habría quedado un arreglo
con buena pinta que no se ejecuta jamás, **que es peor que el fallo, porque hace
creer que el caso está cubierto**.

Un barrido mecánico sobre una lista medida es exactamente donde eso se cuela: el
patrón dice «aquí hay un id sin comprobar» y tiene razón, pero no dice que el
método esté muerto por otro motivo.

El arreglo es cambiar una letra, y **por eso mismo no se hace aquí**: enciende en
los quince colegios una pantalla que hoy no existe, y eso es una decisión del
colegio —la misma familia de la [05 §18.4](05-codigo-muerto-y-roto.md) y la
[§22.3](05-codigo-muerto-y-roto.md)—. Fijado por
`test_editar_una_pregunta_es_500_exista_o_no`.

---

## §9. El historial: la pantalla que contesta «¿quién entró?» dice siempre que nadie

Tercer hueco del fichero. `HistorialesController` estaba a **1 de 4**, y es la
pantalla con la que el colegio contesta *¿quién cambió esta nota?* y *¿quién ha
intentado entrar en esta cuenta?*. Fijado por `tests/Contrato/HistorialesTest.php`
(5 casos).

### §9.1. Los intentos fallidos no se encuentran nunca

`Services\Login::anotarIntentoFallido()` escribe el **`username`** en
`bitacoras.affected_person_name`, que es una columna de nombre. Y
`HistorialCalc::intentos_fallidos_de_usuario($user_id)` la busca pasándole **el
`user_id`**, que es un número.

**Nunca casan.** La lista sale vacía siempre, con la fila delante.

Y eso es peor que no tener la pantalla: **una lista vacía se lee como «no ha
pasado nada»**, que es exactamente la respuesta contraria a la que la pantalla
existe para dar. Es la familia de «una respuesta que miente» en su forma más
silenciosa — sin error, sin 500, sin nada que mirar.

**Lo que lo convierte en un descuido y no en una decisión** es que la misma
consulta está en tres sitios y **las otras dos aciertan**:

| Dónde | Qué le pasa |
|---|---|
| `ChangeAskedController` (dos veces) | `$user->username` ✔ |
| `HistorialCalc::intentos_fallidos_de_usuario` | `$user_id` ✘ |

La copia equivocada es justo la que vive en **la clase reutilizable**, o sea la
que parece la buena. Es el reverso de la lección de la
[13 §2](13-actividades.md): allí el peligro era comparar contra la columna
equivocada al escribir un guard nuevo; aquí ya está escrito y lleva años
contestando que no.

El arreglo es cambiar el argumento, y **enciende una pantalla que hoy responde
vacía en los quince colegios** — con lo cual va a la lista de decisiones, no a
la de arreglos, igual que la §8.

### §9.2. Y cualquiera ve las sesiones de cualquiera, con IP y dispositivo

`putDeUsuario()` recibe `user_id` por el cuerpo y `putSesion()` recibe
`historial_id`, y ninguna comprueba de quién es.

Lo que devuelven no es una lista de fechas. `historiales` guarda **`ip`,
`browser_name`, `platform_name` y `device_model`**, y la consulta de `putSesion()`
une con `users` para traer el **`username`**, así que ni siquiera hace falta saber
de quién es la sesión: el id basta y el nombre viene dentro.

Con `auth.personal` delante, eso son los 51 profesores viendo **desde qué sitio y
con qué teléfono entra un compañero** — o el superusuario. Va con la pregunta de
la [09 §5](09-pendientes.md) sobre qué puede hacer el personal entre sí, que es
donde está esta familia entera.

### §9.3. Lo que sí está bien, y por eso se anota

`putSesion()` **sí** comprueba que el historial exista y responde **400** en vez
de reventar. Es el único de los métodos mirados hoy en este fichero que lo hace,
y por eso se fija: cuando alguien haga el barrido de los `[0]` y los `find()`,
este método no está en la lista y conviene que se vea por qué.

---

## Lo que queda de `informes.php`

Por orden de hueco medido:

1. ~~`BolfinalesPreescolarController`~~ — **las tres de escritura, en la §7.**
   Quedan sus dos de lectura, que son boletines y van con `BoletinesTest`.
2. ~~`HistorialesController`~~ — **mirado, está en la §9.**
3. **`PuestosController`** — 1 de 2, y `Boletines2`/`Boletines3` a 3 de 4.
4. Los grandes ya cubiertos a medias por `BoletinesTest` y `ActasEvaluacionTest`,
   que son los que más se imprimen.
