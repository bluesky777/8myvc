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
vivos en los dieciséis colegios y tocarlos enciende o apaga pantallas.

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
los dieciséis colegios, porque sin `permitir` una votación con
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
los dieciséis colegios** una pantalla que hoy no funciona—, y ahora se sabe que
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
  encenderlos cambia pantallas en los dieciséis colegios y eso es despliegue, no
  código.

Una cosa menor y de otro orden: **decidir si la votación de aula se termina o se
retira** (§5.4). Existe a medias y no la usa nadie; mientras siga así, no estorba.
