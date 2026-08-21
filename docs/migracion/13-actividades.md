# Las actividades, por el lado del que las escribe

Cierra la serie de cobertura sobre el dominio de actividades. El lado del alumno
—`mis-actividades/*`— lo cubrió otra sesión el mismo día y está en
[05 §43](05-codigo-muerto-y-roto.md); las cuatro de `opciones/*` las cubría ya
`OpcionesTest`. Aquí van las que faltaban: **`actividades/*` y `preguntas/*`**,
que son con las que un profesor crea el examen, lo edita, lo comparte y lo borra.

Fijado por `tests/Contrato/ActividadesTest.php` (5 casos) y
`tests/Contrato/PreguntasTest.php` (6). Ninguno exige lo correcto: **fijan lo que
hace hoy**, porque son endpoints vivos en los dieciséis colegios.

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

Cerrar esto es de una línea por método —comparar `created_by` con
`$user->user_id`—, y **no se hace aquí** por lo que ya enseñó la §5 de
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

## Lo que queda por mirar

1. ~~`PUT respuestas/actividad`~~ — **mirada, está en la §5.**
2. **`PUT actividades/datos` y `putCompartidas`**, que son los dos listados
   grandes del profesor.
3. **`putDuplicarPregunta`**, que copia una pregunta con sus opciones y es donde
   suelen esconderse los campos que no se copian.
