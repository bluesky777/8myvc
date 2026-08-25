# Las actividades, por el lado del que las escribe

Cierra la serie de cobertura sobre el dominio de actividades. El lado del alumno
—`mis-actividades/*`— lo cubrió otra sesión el mismo día y está en
[05 §43](05-codigo-muerto-y-roto.md); las cuatro de `opciones/*` las cubría ya
`OpcionesTest`. Aquí van las que faltaban: **`actividades/*` y `preguntas/*`**,
que son con las que un profesor crea el examen, lo edita, lo comparte y lo borra.

Fijado por `tests/Contrato/ActividadesTest.php` (10 casos),
`tests/Contrato/PreguntasTest.php` (6) y `tests/Contrato/RespuestasTest.php` (7). Ninguno exige lo correcto: **fijan lo que
hace hoy**, porque son endpoints vivos en los quince colegios.

Las diecinueve rutas llevan `auth.personal`, así que ninguna familia las alcanza
y la pregunta de **quién entra** estaba contestada. La que no había mirado nadie
es la siguiente, y es donde está todo:

> **Qué puede hacer un profesor con la actividad de otro profesor.**

La respuesta es: todo. Y no por falta de datos — `ws_actividades` tiene
`created_by` y `ws_preguntas` tiene `added_by`. De quién es cada cosa **se puede
saber**; ningún método lo mira.

---

## §1. Guardar sin un campo lo borra

`PUT actividades/guardar` asigna **trece** campos seguidos así:

```php
$act->descripcion   = Request::input('descripcion');
$act->duracion_exam = Request::input('duracion_exam');
$act->oportunidades = Request::input('oportunidades');
...
```

`Request::input()` de algo que no viene devuelve `null`. Así que un cliente que
mande solo lo que cambió —que es lo que hace cualquiera que no sepa que este
endpoint espera el objeto entero— **vacía todo lo demás**: la duración del
examen, las oportunidades, el tipo de calificación, el contenido.

`PUT preguntas/guardar` hace lo mismo con siete campos: mandar solo el enunciado
deja la pregunta **valiendo cero puntos** y sin su pista.

No es una fuga ni un permiso. Es un examen configurado que se queda en blanco sin
que nada falle, respondiendo **200 con el objeto ya vaciado dentro**.

### Y una cosa que no es del código: `strict => false`

Al mirarlo por el resultado salió que los campos no quedan todos igual. Unos
quedan en `null` y otros en cadena vacía o en cero, y la diferencia no la decide
el controlador: las columnas `NOT NULL` reciben el `null` y **MySQL lo convierte
en silencio**, porque `config/database.php` lleva `'strict' => false`.

Con el modo estricto puesto, esta misma llamada sería un error en vez de un
vaciado. O sea que aquí hay **dos capas que eligen callar**: el controlador, que
no distingue «no me lo mandaste» de «ponlo a null», y la conexión, que no
distingue «este campo no admite null» de «pon el vacío». Encenderlo no es cosa de
este documento —rompería escrituras por todo el proyecto—, pero conviene saber
que está apagado cuando se lea cualquier cosa de esta familia.

Fijado por `test_guardar_sin_un_campo_lo_borra`, en los dos ficheros.

---

## §2. Nadie mira de quién es

| Ruta | Qué hace con lo ajeno |
|---|---|
| `PUT actividades/guardar` | lo edita entero — y como es la §1, **editarlo y vaciarlo son la misma llamada** |
| `DELETE actividades/destroy/{id}` | lo manda a la papelera |
| `PUT actividades/insert-grupo-compartido` | lo comparte con el grupo que sea |
| `PUT preguntas/update-orden` | le reordena las preguntas |

Con `auth.personal` delante, eso son los **51 profesores** del colegio sobre
cualquier examen. Y el segundo de la tabla tiene consecuencia para el alumno,
aunque parezca de administración: `exigirQueLaActividadLeCorresponda()` —la
comprobación que cerró el lado del alumno— **abre la actividad a un alumno si
está compartida con su grupo**. O sea que `insert-grupo-compartido` es la puerta
por la que se decide quién ve un examen, y no comprueba quién la abre.

### La mina que tiene ese arreglo, y hay que leerla antes de escribirlo

Parece de una línea por método: comparar `created_by` con el usuario. **Escrito
de la forma obvia no funcionaría, y no fallaría: no encontraría nunca nada.**

`ws_actividades.created_by` **no guarda el `users.id` que su nombre sugiere: guarda
el `persona_id`.** Lo escribe `postCrear()` con `$user->persona_id` y lo lee
`putCompartidas()` con lo mismo, así que hoy es coherente. Pero cualquiera que
añada el guard comparando `$user->user_id` —que es lo que uno escribe— obtendría
un `WHERE` que no casa nunca, y el resultado sería **un permiso que deniega todo
en vez de un permiso que no existe**. Con `ws_preguntas.added_by` pasa al revés:
`postCrear()` de preguntas escribe `$user->user_id`.

O sea que las dos columnas de propiedad del mismo dominio guardan **cosas
distintas con nombres igual de genéricos**. Es exactamente lo que CLAUDE.md
advierte —`user_id` y `persona_id` no son lo mismo— aplicado al sitio donde más
caro sale.

Cerrar esto es de una línea por método —comparar `created_by` con
`$user->persona_id`—, y **no se hace aquí** por lo que ya enseñó la §5 de
[11-votaciones.md](11-votaciones.md): en este sistema «de quién es» y «quién
puede tocarlo» no coinciden, y el colegio tiene coordinadores que configuran lo
que no crearon. Es la misma pregunta que dejó abierta la [09 §5](09-pendientes.md)
para las 44 rutas de estructura. **Va con ella, no por separado.**

---

## §3. `find()` donde debía haber `findOrFail()`, tres veces

Solo en `PreguntasController`:

| Método | Qué pasa con un id que no existe |
|---|---|
| `putGuardar` | `WsPregunta::find()` devuelve `null` y sigue asignando propiedades encima → **500** |
| `putToggleOpcionOtra` | igual → **500** |
| `putEdicion` | `DB::select(...)[0]` sobre una consulta vacía → **500** |

Los tres deberían ser 404. Es la misma forma que ya se arregló en
`WsActividad::datosActividadConRespuestas()` el mismo día —allí, además, con el
intento del alumno ya escrito antes de reventar—, así que van tres sitios con el
mismo patrón en el mismo dominio.

### §3.1. Y en `update-orden` no es solo el código de error

`putUpdateOrden()` recorre el `sortHash` del cuerpo y hace `find()` con cada
clave, **guardando dentro del bucle y sin transacción**. Un id que no exista en
mitad del mapa revienta la llamada con las preguntas anteriores **ya guardadas**.

El examen queda con la mitad del orden nuevo y la mitad del viejo, y el cliente
recibe un 500 que le dice que no se guardó nada. Fijado por
`test_un_id_malo_en_el_reordenar_deja_lo_anterior_escrito`.

Esto es lo que hace que el `find()` sin `OrFail` pese más aquí que en los otros
dos: **el 500 miente sobre lo que se escribió**, que es la familia de «una
respuesta que miente» del [09](09-pendientes.md) con una cara nueva — no un 200
que dice que sí cuando fue que no, sino un 500 que dice que no cuando fue a
medias.

---

## §4. Lo que sí sostiene algo, y no es el código

`PUT actividades/insert-grupo-compartido` es un `new WsActividadCompartida()` con
los dos ids del cuerpo y un `save()`. Sin `findOrFail`, sin validación, sin mirar
`created_by`.

Con un id que no existe **no responde 404 ni 422: revienta con la clave
foránea**. Y eso es lo interesante: `ws_actividades_compartidas` **sí** tiene
`FOREIGN KEY` a `ws_actividades` y a `grupos`, así que la integridad la sostiene
el esquema y no el código.

Se anota porque es lo contrario de lo que suele encontrarse en este repo, y
porque marca dónde **no** hay red: las otras tablas del dominio no las llevan.

Fijado por `test_compartir_una_actividad_que_no_existe_es_500`.

---

## §5. La pantalla de corregir: cuatro caminos y uno que funciona

`PUT respuestas/actividad` es una sola ruta y la que más devuelve del dominio:
por cada grupo al que se compartió la actividad, **todos sus alumnos** con
nombre, foto, si terminaron, su `puntaje_manual` y su comentario. Lleva
`auth.personal` desde la [05 §24](05-codigo-muerto-y-roto.md), que la cerró a las
familias — y ahí se quedó la cosa. Nadie había mirado qué responde.

`putActividad()` se abre en cuatro caminos según cómo esté la actividad, y **solo
uno de los cuatro hace lo que dice el nombre de la ruta**:

| Estado de la actividad | Qué devuelve |
|---|---|
| compartida + `para_alumnos` | la lista de corregir, bien |
| compartida + `para_profesores` | **la palabra `Profesores`** |
| compartida + `para_acudientes` | **la palabra `Acudientes`** |
| compartida sin ningún `para_*` | 200 con la actividad y **sin la clave `grupos`** |
| **sin compartir** | **500** |

### §5.1. La rama `else` es una consulta vacía

```php
} else {
    $consulta = '';
    $alumnos  = DB::select($consulta, [$user->year_id]);
}
```

Una cadena vacía como SQL. No es que devuelva mal: es que no se puede ejecutar. Y
el `$alumnos` que saldría de ahí **no se usa después** — se pierde.

O sea que la pantalla de corregir **solo existe si la actividad está
compartida**, y `compartida` vale 0 por defecto en el esquema. El profesor que
llegue ahí recibe un 500. Con ruta y roto se documenta.

### §5.2. Y dos ramas devuelven una palabra suelta

`return 'Profesores';` y `return 'Acudientes';`, literal. No es un error ni una
lista vacía: es el hueco donde nunca se escribió esa pantalla, y ha llegado hasta
aquí devolviendo una cadena con 200 dentro.

Es la familia de «**una respuesta que miente**» en su forma más literal de todas
las que lleva encontradas este repo: se pide la corrección de una actividad de
profesores y llega un 200 con la palabra «Profesores».

### §5.3. El bucle que hace el mismo trabajo N² veces — medido

Dos `for` anidados, y **el de dentro no usa el índice del de fuera**: el exterior
recorre `count($grupos)` y el interior vuelve a lanzar la misma consulta de
grupos y a recorrerlos todos otra vez, pisando `$grupos` con un resultado
idéntico.

El resultado final es correcto, y por eso no lo ha notado nadie. Lo que cuesta es
cuadrático: con G grupos compartidos, la consulta de alumnos —la cara, cinco
joins y todos los matriculados de cada grupo— se ejecuta **G × G veces en vez de
G**.

Medido con tres grupos: **nueve consultas donde debían ser tres**
(`test_la_consulta_de_alumnos_se_repite_al_cuadrado`). Se mide y no se afirma
porque «esto parece O(n²)» es justo lo que este repo pide comprobar.

El arreglo es borrar el bucle exterior, y **no se hace aquí**: va con la forma de
la respuesta ya fijada al lado (`test_la_lista_de_corregir_trae_los_alumnos_del_grupo`),
que es lo que convierte ese borrado en un cambio comprobable en vez de uno a
ciegas.

### §5.4. Y la §2 pega aquí más fuerte que en ningún sitio

`putActividad()` tampoco mira `created_by`. En los demás métodos eso significa
tocar la configuración de un examen ajeno; aquí significa **leer los datos
personales de todos los alumnos** de todos los grupos a los que se compartió, con
su foto y con su nota. Fijado por `test_el_personal_abre_la_correccion_de_otro`.

---

## §6. El cuarto tipo de usuario cae por el hueco, otra vez

`PUT actividades/datos` es el listado con el que el profesor entra al módulo. Su
`putDatos()` tiene **dos ramas**:

```php
if ($user->is_superuser) { ... }
if ($user->tipo == 'Profesor') { ... }
```

Un **administrativo** —tipo `Usuario` sin superusuario, que es lo que son las
secretarias— no entra en ninguna. `mis_asignaturas` y `otras_asignaturas` se
quedan como los arrays vacíos con los que empiezan.

**Y responde 200 con la lista de grupos dentro**, que es lo que lo hace difícil
de ver: la pantalla se pinta, el selector de grupos se llena, y al elegir un
grupo no aparece ninguna asignatura. No parece un permiso que falta; **parece que
el grupo no tiene actividades**.

El mismo cuerpo, el mismo grupo y un superusuario sí las recibe. Fijado por los
dos lados —`test_un_administrativo_recibe_las_listas_vacias` y
`test_el_superusuario_si_recibe_las_asignaturas`—, porque un test que solo mira
el vacío no distingue «no le llega» de «no hay».

### Es la misma forma, y van cuatro

El contexto de usuario tiene **cuatro** ramas —Profesor, Alumno, Acudiente,
Usuario— y el cuarto es el que se olvida:

| Dónde | Qué pasaba |
|---|---|
| [05 §25.3](05-codigo-muerto-y-roto.md) | un administrativo lee en Tardanzas y recibe 400 al subir |
| [05 §44](05-codigo-muerto-y-roto.md) | el `switch` sin rama para el cuarto tipo daba 500 al cambiarle la foto |
| [05 §30.2](05-codigo-muerto-y-roto.md) | `AcudientesController` dejaba a un administrativo sin poder crear acudientes |
| **§6, aquí** | las listas de actividades le llegan vacías, en 200 |

Cuatro sitios, cuatro consecuencias distintas —400, 500, un permiso denegado y
una lista vacía— y la misma causa: el tipo `Usuario` es el que no tiene una
pantalla propia que lo pruebe, así que nadie lo ejerce hasta que alguien mira.

### La generalización que salió de aquí era falsa, y cómo se cayó

De lo anterior salió una conclusión que **estuvo escrita en este documento y hubo
que retirarla**: que buscar `tipo == 'Profesor'` sin `else` daba la lista de los
sitios con el mismo hueco, y que esa lista marcaba el código sin migrar. Se
retira entera, y el porqué vale más que lo que decía.

**Tres mediciones del mismo patrón dieron tres listas distintas**, y cada una
llevaba dentro un límite arbitrario que no se veía:

| Cómo se midió | Sin salida |
|---|---|
| `$user->tipo` y una ventana de 25 líneas para buscar el `else` | 12 |
| emparejando llaves, otra sesión | 9 |
| emparejando llaves **y** aceptando `abort`/`return` como salida | **6** |

La ventana de 25 líneas se inventó tres falsos positivos, los tres de
`CalendarioController`. Sobre ellos llegué a escribir que *«si el hueco está ahí,
el calendario entero le llega vacío a un administrativo»* y a escribir un test
para demostrarlo. **El test lo desmintió**: responde **404 «No tienes permiso»**,
porque el `abort` está a más de veinticinco líneas del `if`. El guard funciona y
siempre funcionó.

Peor todavía: para apuntalar aquella afirmación había señalado como prueba un
ternario `$user->tipo == 'Usuario' ? ...` **dentro** de la rama que excluye a los
`Usuario`, y dije que no se ejecutaba nunca. Sí se ejecuta: los superusuarios son
de tipo `Usuario`, y entran por el `|| $user->is_superuser` de al lado.

**Y las seis que sobreviven a la mejor medición tampoco son huecos**, revisadas
una a una:

- `AsignaturasController:226`, `GruposController:50`, `ImagesController:44` —
  **enriquecen**: al docente se le añaden sus pedidos, sus grupos de titularía,
  sus imágenes. Al que no lo es no le falta nada, así que no necesita `else`.
- `NotasController:195` y `:247` — **acotan**: `$profesor_id` se inicializa vacío
  tres líneas antes. El `else` está escrito, como valor por defecto.
- `PerfilesController:416` y `TSubirController:71` — **cortan con `abort()`**. No
  tener `else` no es no tener salida.
- Las de `ChangeAskedAssignmentController` son la [05 §48](05-codigo-muerto-y-roto.md),
  ya arregladas.

Queda **una sola de la lista que sea un fallo de verdad: la §6 de aquí**, y no se
encontró con el patrón —se encontró leyendo, y se demostró con un test.

### Lo que sí sirve, que es lo que queda de todo esto

**El patrón sintáctico tiene precisión cero.** Lo que distingue un hueco de las
otras cuarenta y una apariciones son dos condiciones juntas:

> el `if` **envuelve el método entero**, y **dentro hay lo único que el método
> produce** —la escritura, o las listas que devuelve—.

Que son justo las dos que `tools/respuestas-que-mienten.py` ya exigía, y por eso
esa herramienta encuentra tres y no doce. **Buscar el `else` que falta no es
buscar el fallo**: el `else` que falta es sintaxis y el fallo es que la respuesta
salga bien habiendo hecho nada.

### Y la correlación con el estilo es real, pero no dice lo que parecía

Medido dos veces, por dos caminos y por dos personas, con el mismo resultado:

| Estilo | Con salida | Sin salida |
|---|---|---|
| `$user = User::fromToken()` — el de siempre | 13 | 12 |
| `$this->user` — el del trait | 17 | **0** |

Cero en el grupo del trait, las dos veces. La correlación existe. Lo que no se
sostiene es lo que se dedujo de ella: **marca estilo, no riesgo**. El código viejo
resuelve al usuario con `$user =` y además escribe ramas que enriquecen; las dos
cosas van juntas sin que una cause la otra, y presentarla como marcador de riesgo
manda al siguiente a auditar sitios correctos.

Lo que sí se lleva uno de aquí: **una correlación limpia entre dos poblaciones no
es una causa**, y la tentación de escribirla como si lo fuera es mayor cuanto más
limpio sale el cero.

### §6.1. El segundo listado tiene el mismo hueco, y peor

`PUT actividades/compartidas` repite las dos ramas, y la diferencia con
`actividades/datos` es que allí las listas se inicializan vacías arriba y aquí
**las tres claves `actv_*` no se inicializan**. Al administrativo no le llegan
vacías: **no le llegan**.

Para el cliente eso es `undefined` en vez de `[]`, que es la diferencia entre una
tabla vacía y un error de JavaScript. Fijado por
`test_el_segundo_listado_no_le_manda_ni_las_claves` y su contrario.

### §6.2. Y el `[0]` de siempre, dos veces en el mismo método

Las dos ramas resuelven el grupo con
`DB::select($consulta, [Request::input('asign_id')])[0]->grupo_id`. Con una
asignatura que no existe, 500. Fijado por
`test_pedir_por_una_asignatura_que_no_existe_es_500`.

Con éste van **seis** sitios del dominio con la misma forma: los tres de la §3,
el de la §5, y estos dos.

---

## §7. Dos reglas del examen que solo existen en el front

Medido con `tools/interruptores-que-nadie-lee.py` cruzando los tres clientes:

```
ws_actividades.can_upload    <-- lo mira myvc_front
ws_actividades.one_by_one    <-- lo mira myvc_front
```

El backend **no las mira**: las guarda, las sirve y ningún `if` decide con ellas.
Quien las obedece es la pantalla — `one_by_one` es «una pregunta cada vez» y
`can_upload` es si el alumno puede adjuntar archivo.

**Es exactamente la forma que cerró la §6.1 de este documento y la §2.1 de
[11-votaciones.md](11-votaciones.md)**: la regla existe, la pinta el cliente, y
quien llame a la API directamente se la salta. Un alumno que responda por HTTP en
vez de por la pantalla contesta las preguntas en el orden que quiera aunque el
profesor haya pedido una a una.

Y el aviso que trae la propia herramienta, que es el que hace falta aquí:

> Estar en el cliente no es lo mismo que estar bien. `vt_votaciones.locked` la
> miraba el front —para pintar un candado— y aun así se podía votar en una
> votación cerrada.

O sea que «lo mira el front» **no cierra el caso, lo abre**: dice dónde está hoy
la regla, no que la regla se cumpla.

No se arregla aquí. Comprobarlas en `putSeleccionarOpcion()` es de dos líneas,
pero decide si un examen empezado a medias se puede terminar salteado, y eso es
una decisión del colegio como lo fue `in_action`. Va con las de la §5 de
`09-pendientes.md`.

**Y una que no es fallo**: `ws_preguntas.aleatorias`, `ws_contenidos_preg.is_cuadricula`
y `ws_actividades_resueltas.is_puntaje_manual` no las lee nadie **en ninguna
parte**, y sus tablas están vacías en la copia de desarrollo. Eso es esquema
muerto, no una regla que no se cumple. Se anota para que nadie las persiga.

---

## Lo que queda por mirar

1. ~~`PUT respuestas/actividad`~~ — **mirada, está en la §5.**
2. Nada de la lista de la §6: se revisó entera y no queda ninguna. Lo que sí
   queda es pasar `tools/respuestas-que-mienten.py` cada vez que se ensanche,
   que es lo que sí encuentra esta familia.
3. ~~**`putDuplicarPregunta`**~~ — **mirada el 21 ago 2026, y la corazonada no
   era.** Copia todo lo que está vivo. Lo que no copia no son campos perdidos:

   - `ws_opciones.image_id` **no lo escribe nadie** —ni el backend ni ninguno de
     los cuatro clientes— y hay **0 filas** con valor. Columna muerta.
   - `ws_opciones_cuadricula` tampoco se copia, y es lo mismo un piso más arriba:
     **la tabla no tiene un solo `INSERT` en toda la API** y está vacía. Es el
     hueco del cuarto tipo de pregunta de la [§6](#6-el-cuarto-tipo-de-usuario-cae-por-el-hueco-otra-vez):
     el tipo «Cuadrícula» se lee y no se escribe, así que **duplicar no puede
     perder lo que no se puede crear**.
   - El `// Debo modificarlo` que el autor dejó al lado de `orden` **ya lo
     resuelve el front**: `EditarActividadCtrl.duplicar_pregunta` pone
     `pregunta.orden = $ctrl.actividad.preguntas.length` antes de mandar. El
     comentario describe una tarea hecha en el otro lado, que es de las cosas que
     más tiempo hacen perder.

   Lo que sí salió de mirarla, y queda fijado por `DuplicarPreguntaTest`:

   - **Sin `opciones` en el cuerpo es un 500 con la pregunta ya creada.**
     `count()` sobre el null de un `Request::input` ausente es un TypeError desde
     PHP 8 —el mismo de la [05 §13](05-codigo-muerto-y-roto.md)— y llega *después*
     del `save()`. No hay camino real que llegue así, porque el front manda el
     objeto entero de una pregunta que ya existe; se deja escrito para que se vea
     el día que alguien toque el método.
   - **Responde 201 y no 200**, y no lo escribió nadie: Laravel lo pone solo
     cuando la acción devuelve un modelo con `wasRecentlyCreated`. Sus hermanas
     devuelven cadenas o modelos existentes y salen 200. **El código depende de
     qué se devuelve, no de qué se hace.**
   - Y `added_by` guarda el `user_id`, comprobado y fijado — que es la mitad de la
     mina de la [§2](#2-nadie-mira-de-quién-es): su hermana
     `ws_actividades.created_by` guarda `persona_id`. El día que se escriba el
     guard de propiedad, este test dice cuál es cuál.
