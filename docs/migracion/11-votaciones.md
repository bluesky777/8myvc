# Las votaciones del colegio

Sale de la serie de cobertura del 21 de agosto de 2026, por el lado que le tocó
a la sesión que trabajaba en paralelo. `tools/cobertura-de-rutas.py` daba **cero
tests propios** para las 26 rutas de `votaciones`, `aspiraciones`,
`participantes`, `candidatos` y `votos`: las tocaban los barridos —que miran el
guard— y nadie más.

Los guards ya estaban puestos y `AutorizacionTest` los fija. Lo que no había
mirado nadie es **qué devuelven y qué escriben**, y ahí está todo lo de abajo.

Vive en su propio fichero, y no dentro de
[05-codigo-muerto-y-roto.md](05-codigo-muerto-y-roto.md), solo porque se escribió
con tres sesiones trabajando a la vez sobre el mismo árbol. **Enlázalo desde el
05 y el 09 cuando se junte todo**; el contenido es de la misma familia.

Todo lo de aquí está fijado por `tests/Contrato/VotacionesTest.php`. **La §1 está
arreglada** —se miró el front, y lo que se vio allí cambió cuál era el arreglo—;
las otras tres fijan lo que hace hoy sin exigir lo correcto, porque son endpoints
vivos en los quince colegios y tocarlos enciende o apaga pantallas.

---

## Lo que enseña este dominio, y vale para el resto

Una elección tiene dos reglas que **no son de autorización**:

> El recuento no se ve mientras se vota, y cada uno vota una vez.

Ningún guard puede comprobar eso. `auth.personal` sabe si quien llama es del
colegio; no sabe si la urna está abierta. Así que estas reglas viven dentro del
controlador **o no viven** — y es exactamente donde no miraba ninguna de las
herramientas: el barrido mira quién llega, larastan mira si el código puede
funcionar, `inventario-autorizacion.py` mira la firma.

Es la misma forma que apareció el mismo día en las actividades: `in_action` es el
interruptor con el que el profesor abre el examen, y tampoco lo comprueba nadie
(ver `MisActividadesTest`). **La regla de procedimiento no tiene guard, y por eso
solo aparece mirando el resultado.**

---

## §1. El conteo viajaba con la papeleta — **arreglado el 21 ago 2026**

`PUT api/votos/show`, sin más guard que el token.

```php
if ($votaciones[$j]->can_see_results || Request::input('permitir')) {
```

Ese `if` decidía **dos cosas con una sola condición**, y ahí estaba todo.

### Lo que el front quería de verdad

Se fue a mirarlo antes de tocar nada, y lo que se encontró cambió el arreglo.
`permitir` lo manda una pantalla viva: `TarjetonesCtrl`
(`panel.actividades.votaciones.tarjetones`) pide `permitir: true`. Su hermana
`ResultadosCtrl` pide `permitir: false`. Los dos controladores son idénticos byte
a byte salvo ese campo — lo dice un comentario del propio front.

Y la clave está en las plantillas: **`tarjetones.html` no dibuja `cantidad` por
ningún sitio** —cero apariciones; solo foto, plancha y nombre—, mientras que
`resultados.html` la dibuja cuatro veces.

> O sea que para el front `permitir` **nunca significó «déjame ver los
> resultados»**. Significa «dame la papeleta aunque los resultados estén
> ocultos», porque un tarjetón para imprimir necesita la lista de candidatos.

El backend lo implementaba devolviendo el payload entero, con `cantidad` y
`total` de cada candidato dentro. La pantalla no los pintaba; **el JSON los
llevaba**.

### Quién lo alcanzaba

En `votacionesInicio.html` los botones *Configurar*, *Participantes* y
*Candidatos* llevan `ng-if="$ctrl.hasRoleOrPerm(['profesor','admin'])"`. *Votar*,
*Resultados* y ***Tarjetones* no llevan ninguno**: un alumno ve el botón. Es la
asimetría de siempre —el patrón aplicado en los tres de al lado y olvidado en el
cuarto—, y aquí significaba que **cualquier alumno con la elección abierta tenía
el escrutinio en vivo en su navegador**, a un F12 de distancia.

Ninguna otra app lo llama: `myvc_flutter` no toca `votos/show`.

### El arreglo, y por qué no fue el de una línea

Lo primero que se propuso —quitar `|| Request::input('permitir')`— **era la
opción mala, y solo se supo después de mirar el front**: apagaría el tarjetón en
los quince colegios, porque sin `permitir` una votación con
`can_see_results=0` no devuelve aspiraciones y la papeleta sale en blanco.

Se separaron las dos decisiones que el `if` mezclaba:

| Quién decide | Qué |
|---|---|
| `permitir` | si viaja la **estructura**: aspiraciones y candidatos |
| `can_see_results` | si viaja el **número**: `cantidad` y `total` |

Con los resultados ocultos, el tarjetón recibe su papeleta completa y **sin
`cantidad` ni `total` en ningún candidato**, incluido el «Voto en Blanco». Con
`can_see_results=1` todo vuelve como estaba.

**No hace falta tocar el front ni desplegarlo**, que es lo mejor del arreglo: el
tarjetón sigue pintando lo mismo, porque lo que se retira es justo lo que no
dibujaba. Solo se despliega la API — y eso sí, colegio a colegio.

De regalo, deja de hacer una consulta por candidato (`VtVoto::deCandidato`) cada
vez que alguien abre el tarjetón.

Fijado en los dos sentidos por
`test_con_los_resultados_ocultos_llega_la_papeleta_sin_el_conteo` y
`test_con_los_resultados_visibles_el_conteo_llega`. Comprobado al revés:
desactivando el arreglo cae el primero y **solo** el primero.

### Y una trampa del seed que salió montando esto

`VtCandidato::porAspiracion()` tiene dos consultas: una comentada, que unía con
profesores, alumnos, acudientes y usuarios, y **la que corre, que une solo con
`alumnos`** —con matrícula en MATR/ASIS/PREM y filtrando `usus.year_id`.

Consecuencia para cualquiera que escriba un test aquí: un candidato cuyo
`user_id` no sea el de un alumno matriculado en ese año **no da error, desaparece
de la papeleta en silencio**. La primera versión de este test ponía `user_id => 1`
y la lista salía con un solo elemento —el «Voto en Blanco», que se añade
después—; un `assertNotEmpty` encima pasaba sin haber mirado ni un candidato. Por
eso el test cuenta tres y compara los ids, en vez de mirar si está vacía.

Y consecuencia para el producto, que no se ha tocado y queda anotada abajo: **un
profesor no puede aparecer en la papeleta**, aunque `votan_profes` exista.

---

## §2. Con el candado echado se sigue votando

`POST api/votos/store`, sin más guard que el token. **`postStore()` no lee
`locked`.**

Y `locked` es el que cierra la urna, con estas palabras de Joseth el 21 de agosto
de 2026:

> **«Si está lock entonces nadie puede votar.»**

Así que esto es un fallo, y de los que tienen regla clara: falta una comprobación
que el colegio da por hecha.

### §2.1. `in_action` NO es un candado, y creerlo llevaba a romper algo

Esta sección decía antes que la elección tenía «cuatro señales de si está
abierta» —`in_action`, `locked`, `fecha_inicio`, `fecha_fin`— y que `postStore()`
no leía ninguna. **Contar `in_action` entre ellas estaba mal.** Lo que hace, en
palabras de Joseth:

> «`in_action` hace que el front mande, después de que un usuario se logueó,
> directo a la pantalla de votaciones para que vote. Si `in_action` es false,
> entonces no afecta a nadie: igual puede ir al menú y abrir la pantalla a la que
> el front no lo mandó automáticamente.»

O sea que **es un redirector, no un permiso**. Que `postStore()` no lo mire es
correcto, y escribir «que no se vote si la elección no está en acción» —que es lo
que sugería la versión anterior de este documento— **habría apagado la votación
por el menú**, que es un camino legítimo.

Por eso el test que lo fijaba se partió en dos: uno que dice que con `locked` se
vota (fallo) y otro que dice que sin `in_action` se vota (**correcto, y fijado
para que el arreglo del primero no se lo lleve por delante**).

Es una lección sobre los nombres: `in_action` suena a candado, está al lado de
`locked` en la misma tabla, y **el código que no lo comprueba tenía razón**. Sin
preguntar, el arreglo «obvio» era el equivocado.

**Y la lección tiene una segunda mitad que la hace peor:** `in_action` existe
también en `ws_actividades`, y **allí sí es un candado**. La sesión que cubrió las
actividades lo cerró el mismo día con una decisión de Joseth
([05 §43.1](05-codigo-muerto-y-roto.md)), tomada sabiendo qué apagaba.

O sea que **la misma columna, con el mismo nombre, significa cosas opuestas en dos
módulos**: en actividades decide si se puede entrar al examen; en votaciones solo
decide si el front te lleva de la mano. Saber lo que hace en un sitio **no vale**
para el otro, y las dos veces hay que preguntar.

Es la trampa más barata que hay en este repo: no exige leer mal el código, solo
exige haberlo leído bien en otro fichero.

### §2.2. Lo que sigue sin comprobar, y sí cuenta

- **`locked`**, que es el candado de verdad — el fallo de esta sección.
- **`vt_votos.locked`**: la consulta de `verificarNoVoto()` lo trae en el
  `SELECT` y no lo usa; un voto marcado como bloqueado se sustituye igual.
- **`fecha_inicio` y `fecha_fin`**: `VtVotacionesController` solo las escribe al
  crear la votación. Nadie las lee para decidir nada.
- Que quien vota esté en el censo (`vt_participantes`), y que el candidato
  pertenezca a la votación que dice el cuerpo.

Fijado por `test_con_el_candado_echado_se_sigue_votando`,
`test_sin_estar_en_accion_se_vota_y_asi_debe_ser` y
`test_un_voto_bloqueado_se_sustituye`.

---

## §3. `verificarNoVoto()` no verifica: borra

El nombre dice que comprueba. Lo que hace es buscar el voto anterior del mismo
usuario en la misma aspiración y **mandarlo a la papelera** para que quepa el
nuevo.

O sea que votar dos veces **cambia el voto**, no lo duplica. Y eso es lo que
salva a la §2 de ser un fallo grave: **el recuento no se infla**. Es la propiedad
de la que depende todo lo demás en este dominio, así que está fijada aparte
(`test_votar_dos_veces_cambia_el_voto_y_no_lo_duplica`), incluida la mitad que se
olvida: el voto viejo queda en la papelera, no borrado de verdad, así que el
rastro de que hubo un cambio existe.

Si cambiar el voto mientras la urna está abierta es lo que el colegio quiere, el
código está bien y **el nombre es lo que miente**. Si no lo es, el arreglo es de
la §2 —mirar `in_action`—, no de aquí.

### El aviso que hay que leer antes de tocar esto

**Este módulo se salva de un fallo grave por culpa de un segundo fallo, y los dos
se documentan juntos por eso.**

La §2 dice que se vota con la urna cerrada. Lo único que impide que eso sirva para
meter mil votos al mismo candidato es que `verificarNoVoto()` borre el anterior:
un usuario, un voto vivo por aspiración, se ponga como se ponga. O sea que el
método mal llamado **es el que sostiene el recuento**.

De ahí sale la advertencia, que es lo que de verdad hay que recordar de esta
sección: **el día que alguien «arregle» el borrado** —porque el nombre dice
`verificar` y borrar parece un descuido— **enciende el fallo de la §2 sin haberla
tocado**, y el recuento se vuelve sumable. El orden correcto es al revés: primero
mirar `in_action` y `locked` en `postStore()`, y solo después decidir qué hace
`verificarNoVoto()`.

Es la misma trampa que la §11.2 del 05 —una línea que parece un descuido y es lo
único que sujeta algo—, y por eso el test que la fija
(`test_votar_dos_veces_cambia_el_voto_y_no_lo_duplica`) no está entre los que
documentan fallos: está entre los que **protegen una propiedad buena**.

---

## §4. `votos/update` y `votos/destroy` no tocan votos: tocan candidatos

Los dos métodos están en `VtVotosController` y los dos hacen
`VtCandidato::findOrFail($id)`.

- `DELETE api/votos/destroy/{id}` **borra un candidato de la papeleta**, con
  todos sus votos apuntándole. El id que espera es de `vt_candidatos`, no del
  voto.
- `PUT api/votos/update/{id}` rellena `tipo` y `abrev` sobre un candidato, y
  **`vt_candidatos` no tiene esas columnas** — el esquema congelado dice
  `plancha` y `numero`. Como el modelo va con `$fillable = []`, Eloquent lanza
  `MassAssignmentException` antes de llegar a la base y el `catch` la convierte en
  422. Responde lo mismo con cualquier cuerpo, y no escribe nada nunca.

Las dos se quedan y se documentan, por la regla: **sin ruta y roto se borra; con
ruta y roto se documenta.** Las dos llevan `auth.personal`.

Fijado por `test_votos_destroy_borra_un_candidato` y
`test_votos_update_responde_422_con_cualquier_cuerpo`.

---

## §5. Los seis interruptores de la elección

`PUT votaciones/set-actual`, `set-in-action`, `set-locked`,
`set-permiso-ver-results`, `set-votan-profes`, `set-votan-acudientes`. Todos con
`auth.personal`, o sea los 51 profesores del colegio.

Los seis reciben el `id` **por el cuerpo** y su `UPDATE` no lleva condición de
dueño ni de año. Cualquiera del personal cierra, abre, destapa o pone como actual
la elección de cualquier otro.

El que más pesa es `set-permiso-ver-results`, porque **llega al mismo sitio que la
§1 por otro camino**: la §1 se arregló para que el conteo no viajara con la
papeleta, y esto enciende `can_see_results` en la fila, que es el interruptor de
verdad. Fijado por `test_el_personal_destapa_los_resultados_de_la_votacion_de_otro`
y `test_el_personal_abre_el_candado_de_la_votacion_de_otro`.

### §5.1. Dos escriben en la papelera y cuatro no, y no es una decisión

Es **por dónde se escribe**:

| Cómo | Cuáles | Qué pasa con una votación borrada |
|---|---|---|
| `VtVotacion::where('id',$id)->update(...)` | los otros cuatro | el scope de `SoftDeletes` los para |
| `DB::statement('UPDATE vt_votaciones v SET ... WHERE v.id=?')` | `set-actual`, `set-in-action` | entran |

Nadie escribió esa protección: la puso el modelo. Es la lección del
[09](09-pendientes.md) —«la misma protección, dos caminos, y solo uno cubierto»—
otra vez, y esta con el agravante de que **los dos caminos están en la misma
clase, a setenta líneas de distancia**. En un proyecto con 990 consultas crudas,
lo que protege el modelo protege el camino que casi no se usa.

El daño hoy es pequeño, porque los lectores filtran la papelera. Lo que deja es
filas borradas cambiando de estado, así que un `restore` devuelve algo distinto
de lo que se borró. Fijado por
`test_solo_los_dos_interruptores_de_sql_crudo_escriben_en_la_papelera`.

### §5.2. Sin el campo en el cuerpo, la mitad se enciende sola

`Request::input('locked', true)`. El valor por defecto es **`true`** en `locked`,
`votan_profes`, `votan_acudientes` y `actual`, y **`false`** en `in_action` y
`can_see_results`.

O sea que una llamada con solo el `id` dentro **hace cosas opuestas según a qué
interruptor le llegue**: cierra la elección, o tapa los resultados, o abre el voto
a los acudientes. Es la forma de la [05 §26](05-codigo-muerto-y-roto.md) —donde una
llamada sin `clave` dejó a 1.280 alumnos con la contraseña vacía—, aquí con daño
pequeño y la misma cara. Fijado por `test_sin_el_campo_el_candado_se_cierra_solo`.

### §5.3. Y lo que no se toca: «la votación actual» significa dos cosas

Esto no es un fallo con arreglo obvio, y por eso no lleva test que lo exija —pero
es lo que hay que saber antes de acotar los interruptores por dueño, que es el
arreglo que pide la §5.

- `VtVotacion::actual($user)` y `actualInAction($user)` filtran **por
  `user_id`**: para las pantallas de administración, la elección actual es la
  **del profesor que mira**. Cada uno tiene la suya.
- `VtVotacion::actualesInscrito($user)` —la que usa `en-accion-inscrito`, o sea
  **la pantalla de votar**— no filtra por `user_id`: `WHERE actual=true and
  in_action=true`. Es **global**.

Y el `UPDATE` que apaga a las demás en `set-actual` filtra `v.user_id=?`, o sea
que es coherente con la primera lectura y no con la segunda. Consecuencia: dos
profesores pueden tener cada uno «su» elección actual y en acción, **el alumno ve
las dos**, y ninguno de los dos profesores puede verlo desde su pantalla.

Puede que sea lo que el colegio quiere —varias elecciones a la vez— o puede que
no. Lo que no puede ser es que dependa de qué consulta se lea, así que **se
contesta antes de tocar los interruptores**.

### §5.4. Contestada entera — 21 ago 2026, y la respuesta cambia el arreglo

Primero llegó, a través de otra sesión, que el dueño de una elección es **«una
por profesor»**. Con eso, **acotar los seis interruptores de la §5 por dueño
queda autorizado**: el que la creó la administra.

> **Ese «una por profesor» vale para quién ADMINISTRA, no para quién VOTA**, y hay
> que leerlo pegado a lo de abajo o se entiende que vale para las dos cosas.
> Aplicarlo a la pantalla de votar apaga la votación general del colegio.

Después Joseth dio el contexto que faltaba, y es el que impide aplicar esa misma
respuesta al otro lado:

> «La idea inicial es que los profes pudieran hacer sus votaciones en el salón de
> clase para elegir su representante del grupo, **pero no sé si finalicé eso**. Lo
> importante es la votación que hace el colegio en general: cada alumno puede
> votar, y al final un administrador imprime el resultado o lo exporta a Excel.»

Eso explica de dónde salía la contradicción de esta sección. **No eran dos
lecturas del mismo concepto: eran dos funciones, una terminada y otra no.**
`actual()` y `actualInAction()` filtran por `user_id` porque son de la votación
de aula —la que quedó a medias—, y `actualesInscrito()` no filtra porque es la
del colegio, que es la que se usa.

**Y por eso `actualesInscrito()` no se acota, y acotarla habría sido el error.**
Filtrarla por dueño no habría «arreglado una incoherencia»: habría apagado la
votación general, que es la única que funciona. La pregunta que este documento
tenía escrita —*¿la elección de un profesor la votan sus alumnos de asignatura,
los de su grupo como titular, o todo el colegio?*— **se retira**: no hay que
contestarla para seguir, porque la función que la necesitaba nunca se terminó.

Lo que queda de ella, que es distinto y menor: **decidir si la votación de aula
se termina o se retira.** Hoy existe a medias —los interruptores la administran,
`actual()` la lee— y no la usa nadie. Mientras siga así, no estorba.

---

## §6. El censo dice a quién votó cada uno

`PUT participantes/votantes`, con `auth.personal`, o sea los 51 profesores.

Devuelve, por cada matriculado del grupo y por cada cargo de la elección, **las
filas de `vt_votos`** con su `candidato_id` dentro. No es un recuento agregado:
es el voto de esa persona, nominal, junto a su nombre y su documento.

La [05 §18](05-codigo-muerto-y-roto.md) ya lo decía leyendo el código. Aquí queda
fijado **por el resultado**, que es otra cosa: lo que se comprueba no es que la
consulta lo pida, es que **llega al cliente**.

**Y ya no es una pregunta abierta: Joseth lo contestó el 21 de agosto de 2026.**

> **«Las votaciones son secretas.»**

Este documento llegó a plantearlo como decisión del colegio —«puede que la
pantalla exista para auditar quién votó»— y **esa duda se cierra**: no existe
para eso. Con la regla puesta, la §6 y la [§7.1](#71-get-votos-entrega-todos-con-quién-emitió-cada-uno)
dejan de ser hallazgos que esperan criterio y pasan a ser **dos fugas del voto
secreto**, una por una ruta que usa una pantalla y otra por una que no usa nadie.

No se arreglan aquí porque el arreglo no es quitar la ruta: la pantalla del censo
sirve para saber **quién ha votado ya** —que es legítimo y hace falta el día de
la elección— y lo que sobra es **a quién votó**. Son columnas, no rutas: el
`candidato_id` y el `blanco_aspiracion_id` de cada fila de `vt_votos`. Recortarlos
deja la pantalla funcionando y cierra la fuga, que es la misma forma que el
arreglo de la §1.

Y conviene leerla junto a la §3: `verificarNoVoto()` manda el voto anterior a la
papelera en vez de borrarlo, así que **el rastro de que alguien cambió su voto
también existe** —con `deleted_at` puesto, pero con su `user_id` y su
`candidato_id` intactos—. Con el voto secreto confirmado, eso deja de ser una
curiosidad: es la misma fuga en la papelera.

### §6.1. Y no comprueba que el grupo y la elección tengan que ver

`grupo_id` y `votacion_id` llegan por el cuerpo y se usan por separado: uno elige
a los participantes y el otro los cargos. Nadie mira si ese grupo está inscrito
en esa elección —que es justo lo que dice `vt_participantes`—, así que se puede
pedir el censo de un grupo cualquiera contra una elección cualquiera y sale una
tabla **con sentido aparente**: gente de verdad, cargos de verdad, y ninguna
relación entre las dos cosas.

### §6.2. Cuesta una consulta por participante y cargo — medido

Dos bucles anidados, y **la consulta de cargos está dentro del primero**: se
lanza una vez por participante, con los mismos parámetros y el mismo resultado.
Después, una consulta de votos por cada cargo de cada participante.

O sea `P × (1 + A)` para P matriculados y A cargos. Medido contra el grupo del
seed: **37 consultas de cargos donde debía haber una**, y eso antes de contar las
de votos.

Es la misma forma que el bucle de `respuestas/actividad`
([13-actividades.md §5.3](13-actividades.md)): trabajo repetido dentro de un
bucle, resultado correcto, nadie lo nota. **Salió el mismo día en dos dominios
distintos**, lo que sugiere que no es un descuido puntual sino cómo se escribía.

No se arregla aquí: sacar la consulta del bucle es de una línea, pero la línea
solo es segura con la forma de la respuesta fijada, y eso es el test de la §6.

---

## §7. Las dos que faltaban

### §7.1. `GET votos` entrega todos, con quién emitió cada uno

`VtVoto::all()`, sin filtro de año, de elección ni de nada. Cada fila lleva su
`user_id` y su `candidato_id`. Con `auth.personal`, y **no lo llama ningún
cliente**: la pantalla de resultados usa `votos/show`, que sí se acota.

Junto a la §6 completa el cuadro: **el voto nominal sale por dos rutas, una que
una pantalla usa y otra que no usa nadie.** La segunda se puede cerrar sin
preguntarle a nadie el día que se decida la primera.

### §7.2. La papeleta revienta para un alumno, y el guard está en la otra rama

`GET candidatos/conaspiraciones`:

```php
if ($user->tipo == 'Alumno' || $user->tipo == 'Acudiente') {
    $votacion = VtVotacion::actualInscrito($user);
} else {
    $votacion = VtVotacion::actual($user);
    if (! $votacion) { return [['sin_votaciones_propias' => true]]; }
}
$aspiraciones = VtAspiracion::where('votacion_id', $votacion->id)...
```

La comprobación de nulo **existe y funciona — y cubre solo al personal**. Un
alumno que no esté inscrito en ninguna elección en acción, que es el caso normal
casi todo el año, llega al `$votacion->id` con `null` y revienta.

La [05 §18.4](05-codigo-muerto-y-roto.md) ya lo tenía como «responde 500 a
alumnos y acudientes desde siempre». Lo que añade el test es **dónde está la
asimetría**: no falta la comprobación, está en el sitio equivocado. Eso la
convierte en un descuido y no en una decisión, que no es lo mismo a la hora de
arreglarla.

Sigue sin arreglarse por lo que ya decía la §18.4 —mover ese `if` **enciende en
los quince colegios** una pantalla que hoy no funciona—, y ahora se sabe que
es la misma pregunta que la §5.4 por el otro lado: para contestar qué ve un
alumno cuando no hay elección suya hay que saber antes **cuál es la suya**.

---

## Lo que queda de este dominio

Las 26 rutas están miradas y **las tres preguntas que este documento tenía
abiertas están contestadas** (21 ago 2026). Lo que queda es trabajo, no criterio:

**Con regla clara, listos para hacerse:**

1. **`postStore()` tiene que leer `locked`** (§2). «Si está lock, nadie puede
   votar», y hoy se vota. Es la comprobación que falta, no más.
2. **Recortar el voto nominal de las dos rutas del censo** (§6, §7.1). Las
   votaciones son secretas. No se quitan las rutas —saber *quién ha votado ya*
   hace falta el día de la elección—: se quitan las columnas que dicen *a quién*,
   incluidas las de la papelera.
3. **Acotar los seis interruptores por dueño** (§5, §5.4). El que la creó la
   administra.
4. **Sacar la consulta de cargos del bucle** (§6.2): 37 consultas donde va una.

**Con regla clara y orden que importa:**

5. La §2 antes que la §3. `verificarNoVoto()` borra el voto anterior y **eso es
   lo único que impide que votar con la urna abierta a destiempo infle el
   recuento**. Arreglar el borrado primero enciende la §2 sin tocarla.

**Y lo que NO se toca, que es la mitad del valor de esta lista:**

- **`in_action` no se comprueba al votar, y así debe quedarse** (§2.1). Es un
  redirector del front, no un candado. El arreglo «obvio» aquí era el
  equivocado.
- **`actualesInscrito()` no se acota por dueño** (§5.4). Acotarla apagaría la
  votación general del colegio, que es la que funciona.
- Los tres endpoints rotos de la §4 y la §7.2 siguen documentados y sin arreglar:
  encenderlos cambia pantallas en los quince colegios y eso es despliegue, no
  código.

Una cosa menor y de otro orden: **decidir si la votación de aula se termina o se
retira** (§5.4). Existe a medias y no la usa nadie; mientras siga así, no estorba.

---

## §8. El rediseño del 22 sep 2026 — se configura, no se inscribe

> **Esto deja sin vigencia la lista de «Lo que queda de este dominio» de arriba**:
> sus puntos 1 a 5 están hechos, y uno de los «no se toca» se tocó. Se conserva
> tal cual porque explica por qué cada cosa estaba como estaba.

El encargo no era arreglar las rutas: era que **el colegio no tenga que inscribir
a nadie**. La pregunta que lo arrancó es del primer periodo — hay muchachos
asistiendo que no han cerrado matrícula porque deben dinero o una materia del año
pasado, y la primera versión los metía a mano en una tabla de participantes.

### Lo que cambió de raíz

1. **`vt_participantes` ya no existe.** Nunca guardó personas: guardaba ids de
   grupo en una columna llamada `grupo_profes_acudientes`. La sustituye
   `vt_grupos_votacion`, con `participa` y `modo` ('solo' | 'mesa'), y **sin filas
   participan todos los grupos del año**: las filas son excepciones. Con eso se
   fueron las nueve rutas `participantes/*`, y con ellas el voto nominal de la §6.
2. **El censo se resuelve en caliente**: vota quien esté en un grupo vivo del año
   de la votación —matrícula no borrada cuyo `estado` no sea `RETI` ni `DESE`— y
   cuyo grupo no esté excluido. El asistente sin matrícula formal (`ASIS`) vota
   sin que nadie lo apunte, que era el problema de partida.
3. **Un interruptor de estamento más, y sólo uno.** Medido el 22 sep contra
   `simonbolivar` y `caz_zaragoza`: `users.tipo` toma cuatro valores
   —`Alumno`, `Acudiente`, `Profesor`, `Usuario`— y los doce roles de `roles` no
   incluyen cafetería, aseo ni mantenimiento. **No hay dato para separar al
   personal de apoyo de secretaría**, así que `votan_administrativos` significa
   «el personal con cuenta que no es docente» y un `votan_personal_apoyo` habría
   sido una casilla que no selecciona a nadie.
4. **El voto es inmutable, y lo garantiza la base**: índice único
   `(votacion_id, aspiracion_id, user_id)`. `verificarNoVoto()` —que borraba el
   anterior, §3— ya no existe, y `votos/update` y `votos/destroy` —que tocaban
   candidatos, §4— están borrados. `blanco_aspiracion_id` se fue: el voto en
   blanco es `candidato_id` NULL con su `aspiracion_id`.
5. **Las mesas**, para el niño de preescolar que no teclea su contraseña. Alguien
   le abre la papeleta (`mesas/{id}/abrir`), hay cuenta atrás para que se aparte,
   y el voto guarda **dos personas**: el niño en `user_id` y quien condujo en
   `asistido_por`, más `mesa_id`, `origen` y `segundos`. La doble llave —una clave
   que tiene un segundo asistente— es opcional y se guarda hasheada.
6. **Las actas de papel**: cantidades por cargo y candidato, nunca fila por
   alumno. Se suman al escrutinio y se ven aparte en `resultados/{id}`, porque 63
   papeletas no son 63 personas.
7. **La lista nominal sale por una sola puerta**, `auditoria/{id}`, y sólo para
   `Autoriza::esSuperusuario()`. No se usó `puedeVerAuditoria()` porque su
   migración siembra el permiso a rectoría y coordinación. Cada consulta queda
   registrada en `auditoria` con `auditoria_del_voto`, la única lectura del
   sistema que se audita: la fila no dice qué cambió, dice **quién miró**.

### Averías que se encontraron de paso, y no se buscaban

- **`votaciones/en-accion-inscrito` llevaba en 500** desde la migración del voto:
  leía la columna tirada. Era la papeleta entera.
- **El interruptor sin valor en el cuerpo encendía o apagaba según a cuál
  llegara.** Ahora el valor es obligatorio (422 si falta).
- **`conaspiraciones` devolvía `votado: []`**, y `[]` en JS es cierto: la pantalla
  llevaba años creyendo que estaba todo votado. Ahora es un booleano.
- **El total de un cargo dejaba fuera los votos en blanco**, así que los
  porcentajes del tarjetón salían de una base que no era la urna.
- **`GET votos` entregaba la tabla entera con el `user_id` de cada voto** a
  cualquiera del personal. Borrada.
- **El hash de la doble llave viajaba al tarjetón de cualquier alumno.** El
  `$hidden` del modelo sólo actúa sobre Eloquent, y `votaciones/actual` y
  `en-accion-inscrito` leen con `DB::select … SELECT *`. Es un bcrypt de **cuatro
  cifras**: romperlo fuera de línea es cuestión de milisegundos, y con eso la doble
  llave deja de ser una llave. Lo quita `VtVotacion::sinElHash()` en las dos
  consultas crudas; la que sí necesita el hash —`postAbrir`, para el `Hash::check`—
  tiene su propio `SELECT` y no se tocó.
- **Y en el front** (`myvc_front`, `app2/src/app/core/api/mensaje-error.ts`): la
  lista de códigos cuyo cuerpo se enseña al usuario no incluía **409 ni 423**, así
  que todos los mensajes que este rediseño escribió con cuidado —«Ya votaste este
  cargo», «La votación está pausada», «Esa acta ya está firmada»— salían como «No se
  pudo guardar.». O sea el mismo modo de fallo que se vino a quitar del backend,
  reaparecido en la pantalla. Arreglado el 23 sep.

### Dos endpoints que salieron al escribir las pantallas

Ninguno estaba en el encargo: los pidió la pantalla cuando se vio que el modelo
permitía algo que la interfaz no podía ofrecer.

- **`GET censo/{id}/conductores`** — a quién se le puede dar una mesa. `elegibles`
  une el censo de alumnos con `profesores` que tengan contrato del año, así que **el
  personal con cuenta que no es docente no aparecía nunca**, y la «mesa de la
  oficina» —la que lleva una secretaria o una coordinadora, que es un caso real del
  encargo— no se podía montar aunque `vt_mesa_usuarios` acepte cualquier `users.id`.
  Devuelve docentes con contrato **y** `users.tipo = 'Usuario'`, con su `estamento`
  dentro, y exige `is_active` en los dos lados: conducir empieza por entrar, y de las
  14 fichas de docente con cuenta del docker **10 están inactivas**.
- **`censo/{id}/elegibles` devuelve ahora el `estado` de matrícula** (nulo en el
  docente). Sin él, la pantalla de candidatos no podía decir «asiste, sin matrícula»
  en cada resultado de la búsqueda —sólo en el ya elegido, y pagando una consulta
  por grupo—, y ése es justo el muchacho por el que empezó todo este rediseño.

### Lo que queda, y es criterio, no trabajo

1. **`in_action` ahora es un candado al votar, y eso contradice el §2.1 de este
   documento** — *«es un redirector del front, no un candado; el arreglo obvio
   aquí era el equivocado»*, decidido el 21 ago 2026. El encargo del 22 sep pidió
   exigirlo y se exige. Las fechas `fecha_inicio`/`fecha_fin` —que antes no se
   comprobaban y ahora sí— ya dan la ventana de verdad. **Sin decidir**, y
   preparado para revertirse: la comprobación vive **en un solo `if`** dentro de
   `VtVotacion::exigirUrnaAbierta()`, bajo su propio rótulo
   («── `in_action`, y esto es lo que se quita ──»). Es el único sitio del backend
   que lo exige al votar, así que deshacerlo es borrar ese `if`.

   Nació duplicado —`VtVotosController::laUrnaAbierta()` y
   `VtMesasController::exigirUrnaAbierta()`, escritas a la vez por dos agentes
   distintos— y se juntó el 23 sep: las cuatro señales eran idénticas y la única
   diferencia real era que la de votos traía la existencia y la papelera, y
   devolvía la fila. Se quedó ésa, y el método unificado acepta el id o la fila ya
   cargada para que la mesa no repita el `SELECT`. Ningún código de estado cambió.
2. **`vt_votos.created_at` se sella en UTC** y el resto del sistema guarda Bogotá
   (`RelojUnicoTest::SELLAN_EN_UTC` lo deja escrito a propósito). La hora que
   pinta el 409 de «ya votó» y la lista de la mesa va **cinco horas adelantada**
   salvo que el front convierta. Ponerle `SellaConElReloj` metería dos relojes en
   la misma columna. **Sin decidir; lo barato es convertir en el front.**
3. **Cambio de contrato con los dos fronts**: `votos/store` ya no devuelve 200 con
   un `msg` dentro —201, 409, 423, 403, 422— y el AngularJS de `app/` lee el
   cuerpo, no el código. Un «ya votaste» va a caer en su rama de error. No hay
   doble voto posible, pero el mensaje que ve el alumno cambia. **Sin decidir.**
4. **El índice del blanco del acta usa una columna generada `VIRTUAL`** y sólo se
   probó en el MySQL 8 del docker; **producción es MariaDB 10.5**. Debería entrar;
   si no, la salida no es volver a `STORED` sino cambiar el `CASCADE` de
   `vt_acta_votos.candidato_id`, que es decisión.
5. **Anular un acta firmada no existe.** Es otra operación, con otro permiso y su
   propio rastro; pasarla por el `DELETE` le daría a cualquiera del personal la
   llave de deshacer una firma.
6. **`porAspiracion()` sigue uniendo sólo con `alumnos`**, así que un profesor no
   puede salir en la papeleta aunque `votan_profes` exista.
7. **Tests en rojo por decisión, no por avería**, y sin tocar: `Contrato/`
   `VotacionesTest` fija como comportamiento los fallos que se acaban de cerrar
   (votar con el candado echado, votar dos veces cambia el voto, `votos/destroy`
   borra un candidato…), `VotacionesInterruptoresTest`, `VotacionesBorradoTest`,
   `SuperficieDeUnAlumnoTest` monta el censo en `vt_participantes`, y los
   snapshots `rutas.json` y `guards-por-ruta.json` cambian con las rutas nuevas.
   Van después de que el módulo se pruebe a mano.

### El despliegue

Seis migraciones, y **la 400000 tira `vt_participantes` y la 600000 borra filas de
`vt_votos`** (las de la papelera, las irrellenables y los duplicados). Autorizado
el 22 sep 2026: *«las votaciones se pueden ignorar, y si las llegan a necesitar
las sacamos de un backup; ellos nunca miran las votaciones de años pasados»*. En
`simonbolivar` no había ningún duplicado ni ninguna fila irrellenable —
encontrarlos habría sido la noticia.

Hay que correrlas colegio por colegio, y el front todavía no existe: hasta que
exista, la pantalla vieja de participantes queda sin backend.

---

## §9. La fuga del §1 volvió por la otra puerta — **arreglada el 23 sep 2026**

`GET votaciones/en-accion-inscrito` **es la papeleta**: lo que recibe un alumno
cuando entra a votar. Y le colgaba `cantidad` y `total` a cada candidato —y al
voto en blanco— **sin mirar `can_see_results` en ningún sitio**; el interruptor no
aparecía en el método. O sea que cualquier alumno con la elección abierta recibía
el marcador en vivo **en la misma respuesta con la que iba a votar**: sabía quién
iba ganando antes de marcar.

Es la fuga del §1 exacta —«el conteo viajaba con la papeleta»—, cerrada en
`votos/show` el 21 ago y abierta por este otro camino hasta hoy. La encontró la
sesión que escribió la pantalla del kiosco, y dijo la cosa correcta: **su pantalla
no lo pinta, pero taparlo en el front sería lo malo**, porque el dato sigue en el
cable para quien abra las herramientas del navegador.

### El criterio no se inventó: es el que ya estaba escrito dos veces

El de `VtVotosController::putShow()` (§1) y el de
`VtResultadosController::getShow()` (§8.6): la **estructura** viaja siempre
—cargos, candidatos, el blanco—, porque es lo que hace falta para votar; el
**número** sólo con `can_see_results`, y **al personal del colegio se le da
siempre**, que es la mitad de la regla y no una excepción: el interruptor existe
para que los alumnos no vean el marcador mientras se vota, no para que el rector
no pueda mirar su propia elección. Son dos líneas —`$esPersonal` y `$conConteo`—
y la misma lista `NO_ES_PERSONAL` de los otros dos controladores.

> **La segunda mitad de este párrafo —«al personal del colegio se le da
> siempre»— dejó de estar en vigor unas horas después de escribirse.** La estrechó
> Joseth el mismo 23 sep 2026 y el criterio de ahora está en §9.1. El párrafo se
> conserva porque su primera mitad sigue intacta —la estructura viaja siempre, el
> número no— y porque explica de dónde venía el conjunto que se sustituyó; pero
> `$esPersonal` y `NO_ES_PERSONAL` ya no deciden esto en ningún controlador.

### §9.1. El criterio duró unas horas: **lo estrechó el colegio el 23 sep 2026**

La regla, textual: *«Nadie puede ver los resultados hasta que sea permitido; el
coordinador o superuser decide cuándo.»* Y al preguntarle quién es quién, dos
respuestas que son las dos mitades de un solo predicado:

1. **Antes de publicar, el recuento lo ve exactamente quien puede publicarlo.
   Nadie más, ni el docente ni la secretaria.**
2. **Puede publicar**: el superusuario, rectoría, coordinación, **y quien creó la
   elección** (aunque sea un docente).

O sea **ver antes = poder publicar**, y por eso es **un predicado y no dos**:
`VtVotacion::puedePublicarResultados($votacion, $user)` —dueño o superusuario por
`laAdministra()`, más los roles por `Autoriza::puedePublicarCualquierVotacion()`—
preguntado en los **cuatro** sitios: los tres del recuento (`resultados/{id}`,
`votaciones/en-accion-inscrito`, `votos/show`) y el guard del interruptor. Si
alguna vez dejan de contestar lo mismo, la fuga vuelve por la puerta que se quede
corta, que es literalmente lo que pasó entre el §1 y el §9.

**Lo que se retira no es «una excepción menos»: son tres personas reales.** Con
`$esPersonal` = *todo el que no es Alumno ni Acudiente*, el escrutinio en vivo lo
recibían el docente de matemáticas, la enfermera y la secretaria sin que nadie lo
publicara. Y `Autoriza::esAdministrativo()` —`is_superuser || Secretario`— **no
sirve aquí y no se ensanchó**: mete justo a la persona que la decisión excluye, y
lo leen quince llamadas de otros dominios.

#### El tercer sitio se mueve en sentido CONTRARIO, y eso es lo que hace que el diff no sea «quitar `$esPersonal`»

`votos/show` venía del §1 con `can_see_results` **a secas, sin excepción para
nadie**, mientras los otros dos se lo daban a todo el personal. Con el criterio
nuevo los tres contestan lo mismo, y para esa puerta eso significa **abrirla**:
sin ello coordinación no podría mirar el tarjetón con números antes de publicar,
que es justo lo que se le acaba de encargar. **Una sola frase del colegio cierra
dos puertas y abre una tercera.**

#### El interruptor era el único que el que manda no podía tocar

`votaciones/set-permiso-ver-results` va por `cambiarInterruptor()`, y sus diez
hermanos exigen `exigirAdministrable()`: *superusuario o quien la creó*. En un
colegio la elección la monta el docente de democracia escolar, así que **rectoría
y coordinación no podían publicar la elección que la decisión pone en sus manos**.
De ahí `VtVotacion::exigirPublicable()`, el mismo guard con el conjunto nuevo, y un
parámetro en `cambiarInterruptor()` para no duplicar las tres líneas que escriben
la columna. **Los otros diez interruptores no se tocaron**: borrar la elección,
cambiarle el censo o echar el `locked` siguen siendo del dueño.

#### La estructura no se tocó y las claves siguen desapareciendo

Lo que se retira es el número —`cantidad`, `total`, `porcentaje` y la
participación—, nunca la papeleta ni el tarjetón, y la clave **desaparece**: el
cero se rechazó en el §1 y en el §9 por la misma razón y se mantiene. En
`resultados/{id}` sigue viajando `conteo_visible` para que la pantalla explique por
qué no hay números.

Una trampa que salió sola al escribir esto: `VtResultadosController::getShow()`
tenía el `SELECT` con las columnas **nombradas y sin `user_id`**, así que el dueño
de la elección no se habría reconocido a sí mismo — y sin fallar nada, que es el
peor modo de fallo que cabe aquí. Queda anotado en el docblock del predicado.

#### Las dos coordinaciones, que es lo único que queda **por confirmar**

`roles` tiene `Coord académico` (id 9) y `Coord disciplinario` (id 8), y la
decisión dijo «coordinación» a secas. Se metieron **las dos**, porque una elección
de personero no es asunto académico y restringirla al académico —que es lo que
hacen `puedePublicarHorario()` y la nota numérica— habría sido estrechar la frase
por nuestra cuenta. Es una cadena de `ROLES_QUE_PUBLICAN_RESULTADOS`: quitarla es
una línea.

Y **la regla nace casi inerte**: en el docker `Rector`, `Coord académico` y
`Coord disciplinario` tienen **cero usuarios** (contado por `role_id` el 23 sep
2026), y el docblock de `puedePublicarHorario()` ya dejó escrito que
`Coord académico` tiene cero en los dieciséis colegios. Hoy publican los
superusuarios y el dueño de cada elección, y nadie más, hasta que un colegio
reparta los roles.

#### Probado por HTTP con cuatro tokens, contra el docker y la elección 901

Cuatro cuentas de verdad —alumno del censo, docente que **no** creó la elección,
docente **dueño** y el superusuario—, contando apariciones de `cantidad`, `total`
y `porcentaje` en el JSON crudo de los tres endpoints:

| `can_see_results` | alumno | docente ajeno | docente dueño | superusuario |
|---|---|---|---|---|
| `0` | 0 cifras | **0 cifras** | todas | todas |
| `1` | todas | todas | todas | todas |

Y el interruptor: **403** para el alumno (lo pone `auth.personal`) y **403** para el
docente ajeno (*«Publicar los resultados le toca a rectoría, a coordinación o a
quien creó la elección.»*), **200** para el dueño y para el superusuario.

**La prueba distingue, y el control no fue deshacer el código: fue el rol.** Al
mismo docente ajeno, con la misma elección y el interruptor en 0, se le dio
`Rector` → **vuelven las cifras y el interruptor contesta 200**; con
`Coord académico`, igual; y con **`Secretario` vuelve a no ver nada y a recibir
403**, que es la exclusión que Joseth dijo con esas palabras. La estructura llega
intacta en los cuatro casos: dos cargos con sus candidatos.

#### Las dos pantallas del AngularJS congelado: **no cambia nada en los 16 colegios**

`ResultadosCtrl` y `TarjetonesCtrl` viven los dos en
`app/scripts/votaciones/ResultadosCtrl.ts` y llaman al mismo `PUT votos/show`,
sólo con `permitir` distinto (§1).

- **Tarjetones** (`permitir: true`): `tarjetones.html` no pinta `cantidad`, `total`
  ni `porcentaje` —cero apariciones—, así que lo único que cambia es lo que lleva
  el JSON, y a favor: con el interruptor apagado el número ya no viaja a nadie
  salvo a quien puede publicarlo.
- **Resultados** (`permitir: false`): su plantilla abre **todo** el bloque de cifras
  con `ng-if="votacion.can_see_results"` (`resultados.html:4`) y en el `else` pone
  *«Estos resultados están bloqueados en este momento.»* (`:78`). Como `$conConteo`
  es un **superconjunto** de `can_see_results`, **esa pantalla no puede quedarse con
  la tabla puesta y las celdas vacías**: cuando el interruptor está apagado no
  enseña nada —a nadie, ni a rectoría— y cuando está encendido enseña lo de
  siempre.

O sea que **el permiso nuevo no le sirve a la pantalla vieja**: rectoría seguirá
sin ver el recuento antes de publicar *ahí*, porque su propia plantilla mira el
interruptor y no la presencia del conteo. Es una limitación del front congelado, no
de la API, y la pantalla de `app2` ya lo hace bien —discrimina por
`conteo_visible`—. No hay nada que desplegar en `app/`.

#### Y los textos del front que decían lo contrario

Seis sitios de `app2` afirmaban «al personal del colegio se le dan los números
siempre», que era la documentación del criterio viejo: la pista del interruptor en
`paginas/votaciones/config/config.html`, el aviso de la rama sin conteo en
`paginas/votaciones/resultados/resultados.html` («Rectoría decide cuándo…», que se
quedaba corta por los dos lados), y las cabeceras de `datos/votaciones.ts`,
`datos/votos.ts`, `datos/resultados.ts` y
`paginas/votaciones/resultados/resultados.ts`. Corregidos el 23 sep. La pantalla
**no necesitó ningún cambio de lógica**: ya discriminaba por `conteo_visible`, y lo
único que hacía falta era que el aviso dijera a quién pedirle la publicación, ahora
que un docente también cae en esa rama. Queda una frase igual de caducada en un
comentario de `app2/src/app/app.routes.ts` («rectoria todavia no los publico»), que
esta sesión tenía prohibido tocar.

### Las claves desaparecen; no van en cero

Se miró el front antes de elegir, que es lo que hizo que el arreglo del §1 no
fuera el de una línea:

- `app2` declara `cantidad?` y `total?` **opcionales** (`datos/votos.ts`,
  `CandidatoDelTarjeton`) y `paginas/votaciones/votar/` **no los lee**: cero
  apariciones en el componente y en la plantilla;
- `myvc_flutter` **tira los dos campos al leer** y ningún modelo tiene sitio
  donde guardarlos, a propósito (`lib/Http/VotacionesApi.dart`, que además ya
  tenía anotada esta fuga como «lo que queda del §1 de la 11»).

Así que ninguna pantalla se rompe, y el cero sí habría podido romper una: es una
**afirmación falsa** —«este candidato no tiene votos»— y una pantalla que lo
pinte miente con cara de dato bueno, justo donde se está decidiendo el voto. La
clave ausente sólo dice que el conteo no viajó. No se añadió un `conteo_visible`
como el de `resultados/{id}`: allí la pantalla tiene que explicar por qué no hay
números, y en la papeleta no hay números que explicar.

De regalo, un alumno deja de disparar una consulta por candidato más otra por
cargo cada vez que abre la papeleta.

**Probado por HTTP con dos tokens** contra el docker (elección de ensayo 901, dos
cargos, con candidatos que sí resuelven en el año 8): con `can_see_results = 0` el
alumno recibe la papeleta completa y **cero apariciones de `cantidad` y `total`**
en el JSON crudo, y el personal las dos cifras (`total` 4 y 2, los votos de
verdad); con `= 1`, los dos las reciben. Comprobado al revés: forzando
`$conConteo = true` el alumno vuelve a recibirlas, o sea que la prueba distingue.

### Lo que se vio en el mismo método y **no** se tocó

1. **La papeleta lleva el `username` de cada candidato**, más `user_id`,
   `persona_id`, `foto_id` e `imagen_id` — los pone `VtCandidato::porAspiracion()`
   y en estos colegios el `username` es el documento. No es del conteo y sale por
   las dos papeletas —`en-accion-inscrito` y `candidatos/conaspiraciones`—, así que
   recortarlo es una decisión de producto con dos pantallas que mirar, no un
   arreglo de esta línea.
   > **Decidido y hecho el mismo 23 sep, y eran tres puertas y no dos.** Las dos de
   > arriba se cerraron con `VtCandidato::sinElDocumento()` —sin tocar el SQL, porque
   > de la misma consulta salen `candidatos/store` y `mesas/{id}/abrir`, que son
   > respuestas de personal donde el documento sí identifica—. La tercera es
   > **`PUT votos/show`** (`VtVotosController::putShow()`), el tarjetón del AngularJS:
   > tampoco lleva `auth.personal` y servía la misma lista a cualquier alumno que
   > pidiera `permitir: true`. Cerrada por el mismo camino, después de mirar quién la
   > lee —que es el método del §1—: `ResultadosCtrl` y `TarjetonesCtrl`
   > (`app/scripts/votaciones/ResultadosCtrl.ts:41` y `:65`) son los **únicos** que
   > llaman a `VotosApi.resultados()`, y ni `tarjetones.html` ni `resultados.html`
   > pintan `username` —cero apariciones en las dos—; en `app2`,
   > `VotosApi.resultados()` no la llama ninguna pantalla, sólo su propio spec; y
   > `myvc_flutter` no toca `votos/show`. **Ningún front que desplegar**, sólo la API
   > colegio a colegio. Probado por HTTP con dos tokens contra el docker (elección de
   > ensayo 901, con matrícula del año 8 sembrada para los dos candidatos porque si no
   > `porAspiracion()` vaciaba la papeleta en silencio — la trampa del final del §1):
   > **antes, dos `username` en el JSON crudo del alumno; después, cero**, y la
   > papeleta sigue llegando completa —candidatos, foto, plancha, número y el blanco—.
   > Con `can_see_results = 1` el conteo vuelve a viajar para los dos, que es lo que
   > fija el §1.
2. **`candidatos/conaspiraciones` no cuelga conteo ninguno**: cero apariciones de
   `cantidad` en `VtCandidatosController`. Era la otra puerta por donde podía
   estar, y está limpia.
3. **`clave_doble_llave` no viaja**: `VtVotacion::sinElHash()` lo quita, y se
   comprobó en las cuatro respuestas del ensayo (§8 lo dejó cerrado).
4. **`votado` es un booleano** y la fila que lo decide —que trae el
   `candidato_id` dentro— no sale del método, que es lo que pide el §6.
5. **Los candidatos del ensayo 901 no salían en su propia papeleta**: son alumnos
   de los años 1 a 3 y `porAspiracion()` filtra por `usus.year_id`, así que
   desaparecían en silencio —la trampa del final del §1—. Es dato sembrado del
   docker, no una avería del código.
