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

## §2. Se vota con la urna cerrada, y `locked` no cierra nada

`POST api/votos/store`, sin más guard que el token.

`vt_votaciones` tiene dos columnas para el estado de la elección: `in_action`
—«se está votando»— y `locked` —el candado—. `postStore()` **no lee ninguna de
las dos**. Inserta el voto y responde 201 con la votación cerrada y fuera de
acción.

Y hay una tercera que tampoco mira: `vt_votos.locked`. La consulta de
`VtVoto::verificarNoVoto()` la trae en el `SELECT` —`vv.locked`— y luego no la
usa para nada; un voto marcado como bloqueado se sustituye igual que cualquier
otro. La columna existe, se lee, y no decide nada.

Tampoco comprueba que quien vota esté en el censo (`vt_participantes`), ni que el
candidato pertenezca a la votación que dice el cuerpo: `votacion_id` viene del
cliente y solo se usa después, para calcular si el voto quedó «completo».

**Y quedan dos más, que salieron al comprobar otra cosa**: `vt_votaciones` tiene
`fecha_inicio` y `fecha_fin`, y `VtVotacionesController` **solo las escribe** —al
crear la votación, poniéndoles la de hoy si vienen vacías—. Ningún sitio las lee
para decidir nada.

Así que el recuento de la situación es este: la elección tiene **cuatro señales de
si está abierta** —`in_action`, `locked`, `fecha_inicio`, `fecha_fin`— y
`postStore()` **no lee ninguna**. No es que falte una comprobación: es que la
pregunta «¿se puede votar ahora?» no se hace en ningún sitio.

Fijado por `test_se_vota_en_una_eleccion_cerrada` y
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

## Lo que queda por mirar de este dominio

Cubiertas las cinco rutas de `votos` y la forma de las de `votaciones`. Sin mirar
todavía, por orden de lo que parece pesar:

1. **`PUT participantes/votantes`** — el censo entero: 37 KB con documento,
   celular, dirección y correo de cada votante **y a quién votó**. Lleva
   `auth.personal`, así que no lo alcanza una familia, pero sí los 51 profesores.
   Ya está señalada en [05 §18](05-codigo-muerto-y-roto.md); lo que falta es mirar
   la respuesta.
2. **`GET votos`** — `VtVoto::all()`, con el `user_id` de quien emitió cada voto.
   Es el voto secreto, y no lo llama ningún cliente.
3. **`GET candidatos/conaspiraciones`**, que es la papeleta y responde 500 a
   alumnos y acudientes desde siempre ([05 §18.4](05-codigo-muerto-y-roto.md)).
4. **Un profesor no puede ser candidato**, aunque `votan_profes` exista y la
   consulta comentada de `porAspiracion()` lo contemplara. La que corre une solo
   con `alumnos`. No se ha tocado: puede ser lo que el colegio quiere —elecciones
   de personero estudiantil— o un recorte que se hizo y nadie revisó.
5. **Los seis `set-*` de `votaciones`**, que son los interruptores de la elección:
   la votación viaja en el cuerpo y el `UPDATE` de `set-actual` no lleva condición
   de dueño.
