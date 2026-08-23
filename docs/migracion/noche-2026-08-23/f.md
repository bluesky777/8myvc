# Lote F — PIAR, actividades y votaciones

> Sesión `8myvc-2f`, árbol `.worktrees/f`, rama `fix/lote-f-piar-actividades`,
> base `simonbolivar_testing_f`. Noche del 22 al 23 de agosto de 2026.
> Secciones asignadas del [05](../05-codigo-muerto-y-roto.md): **§101–104**.
> Esta sesión cerró antes el [lote B](b.md).

La pregunta del lote eran «los interruptores de lo que ve el alumno». Lo que
salió es otra cosa, y es lo que hace que valga la pena leer esto entero:

> **Dos de las doce rutas no pueden funcionar nunca.** Las dos están enrutadas,
> las dos son alcanzables con un token válido, las dos llevan rotas desde el día
> que se escribieron, y **las dos son copiar-y-pegar que nadie ejecutó**. Ninguna
> se ve leyendo el código: las dos parecen correctas.

| § | Ruta | Qué pasa |
|---|---|---|
| §102 | `PUT piars-config/config` | 500 `Undefined variable $field` |
| §104 | `PUT mis-actividades/guardar` | 500 `Unknown column 'puntaje_por_promedio'` |

Y **ninguna de las cuatro secciones se arregla**. No por prudencia genérica: cada
una tiene su razón escrita abajo, y en la §104 el arreglo evidente es **peor que
el fallo**.

---

## Lo que se arregla, y lo que se anota

| § | Qué | Qué se hizo |
|---|---|---|
| §101 | Sin el campo, `set-votan-profes` **enciende** el voto de los profesores | anotado — su gemela ya se dejó igual |
| §101 | `locked` y `actual` tienen el defecto **al revés** entre crear y conmutar | anotado |
| §101 | Descartado: **no es masivo** sin `id`; y sobre la papelera no escribe | resultado |
| §102 | `piars-config/config`: 500 siempre, por tres razones independientes | anotado — con ruta y roto se documenta |
| §103 | `certificados/store` escribe una fila entera de nulls y contesta 201 | anotado — la §78 se cerró sin esta ruta |
| §103 | Crear y editar piden **claves distintas** para la misma imagen | anotado |
| §104 | `mis-actividades/guardar`: 500 siempre, y **no escribe nada** | anotado |
| §104 | Los dos gemelos de la §74 **tampoco cierran nada** | anotado — la familia queda medida entera |

Cuatro commits, uno por sección. **Ningún fichero de `app/` se toca en este
lote**: las doce rutas se miden y se fijan.

---

## §101 — Seis conmutadores del mismo molde, tres respuestas

`votaciones/set-votan-profes` era la que faltaba de una familia de **seis métodos
idénticos de cuatro líneas**. Leerla sola no dice nada. Ponerlos en una tabla sí,
porque el molde se copió seis veces y **el valor por defecto no se copió con él**.

`Request::input('x', DEFECTO)` decide qué pasa **cuando el campo no viaja**: un
front que manda `{id}` a secas, un formulario a medio rellenar, una petición que
pierde un campo. Y la misma columna tiene **tres comportamientos en el mismo
fichero**:

| Columna | al crear (`postStore`) | el conmutador suelto | `votaciones/update` |
|---|---|---|---|
| `votan_profes` | `true` | **`true`** — se enciende | conserva el valor |
| `votan_acudientes` | `true` | **`true`** — se enciende | conserva el valor |
| `locked` | `false` | **`true`** — se cierra | conserva el valor |
| `actual` | `false` | **`true`** — se hace la actual | conserva el valor |
| `in_action` | `false` | `false` | conserva el valor |
| `can_see_results` | — | `false` | — |

**Dos columnas tienen el defecto al revés entre crear y conmutar.** Crear una
votación sin `locked` la deja abierta; conmutarla sin `locked` la cierra.

La de `locked` ya estaba fijada por
`VotacionesTest::test_sin_el_campo_el_candado_se_cierra_solo`. **La de este lote
es su gemela con el signo cambiado**: sin el campo, `votan_profes` se enciende, o
sea que **abre** el voto a los profesores en vez de cerrarlo. Y la variable local
del método se llama `$locked`, copiada del método del candado sin renombrar: esa
es la huella de por qué los seis defectos no coinciden.

`votaciones/update` —también de este lote— hace lo tercero: `Request::input('x',
$votacion->x)`, que **conserva** lo que no viaja. Es la respuesta contraria a la
de `actividades/guardar`, que con un cuerpo parcial deja el examen en blanco
(13-actividades §1). Tres endpoints, tres criterios, ninguno escrito hasta ahora.

**No se cambia ninguno**: la decisión ya se tomó una vez para su hermana, y lo
que faltaba no era el arreglo sino la tabla.

### Dos cosas descartadas con número, antes de escribir nada

- **No es masivo.** Sin `id`, `where('id', null)` **no casa con ninguna fila** —
  no con todas. Se comprobó dejando la tabla entera en un estado distinguible y
  comparándola después. Un conmutador que escribiera en las once votaciones del
  colegio y contestara 200 sería otra cosa completamente.
- **Sobre una votación de la papelera no escribe** y contesta «Cambiado» igual:
  el modelo lleva `SoftDeletes` y el scope la quita del `UPDATE`.

> **Eso último dio dos mediciones contrarias del mismo interruptor**, y merece la
> pena por qué: la primera cogía «la primera votación por id» **sin filtrar por
> `deleted_at`**, y la primera por id está en la papelera. O sea que el
> interruptor «no hacía nada» en una medición y «se encendía solo» en la otra, con
> el mismo código. Hasta aislar cada llamada en su propio test no había resultado,
> solo dos observaciones. **Un dato que no se reproduce aislado todavía no es un
> dato.**

Y una tercera, pequeña y desagradable: **con el campo en null explícito hace lo
contrario que sin el campo**. `input()` solo aplica el defecto cuando la clave no
está; con la clave presente y en null devuelve null, y la columna lo recibe como 0
porque `config/database.php` lleva `'strict' => false`. Un front que limpia el
campo y otro que lo omite obtienen resultados opuestos de la misma petición.

### `votaciones/show/{id}`, que lleva solo `auth.token`

Era la otra pregunta del lote. Sale la fila de `vt_votaciones` entera: nombre,
fechas y los seis interruptores. **Ni un dato de una persona** — el único id que
viaja es `user_id`, el de quien la creó. No es una fuga.

Lo que sí permite es **enumerar**: un alumno recorre ids y sabe qué votaciones
existen, de qué año son y si están abiertas, incluidas las de años anteriores.
Se anota y no se cierra: cerrarla apagaría la papeleta del alumno, que es quien
tiene que leerla.

---

## §102 — `piars-config/config` no puede funcionar nunca

**500 `Undefined variable $field`**, siempre, para cualquiera que pase el guard.

El método son **restos de dos copiar-y-pegar**:

- Arriba, el bloque `$validFields` de `piars-asignaturas/field` —donde sí tiene
  sentido, porque allí el nombre de la columna llega del cliente y se concatena—,
  copiado con `['documento1','documento2']` dentro y **sin la línea que define
  `$field`**.
- Abajo, el `UPDATE` de `piars-actas-acuerdo/document`, con sus cuatro variables
  —`$fullPath`, `$arr`, `$alumno_id`— que aquí tampoco existen.
- Y por si algo de eso se arreglara: la consulta filtra por `year_id`, que **no es
  una columna de `piars_config`**.

Entre medias, el método calcula `$reporte_default` y `$config` con cuidado —dos
`if` que respetan el valor anterior— y **no los usa**.

**Tres razones independientes por las que no puede escribir**, y ninguna se ve sin
ejecutarla: el fichero se lee bien.

> **No se arregla, y no es pereza.** «Con ruta y roto se documenta»: borrarla
> convierte un 500 en un 404 sin decirle a nadie qué pretendía esa pantalla, y
> reescribirla sería inventar un endpoint que **no llama ningún cliente de los
> cuatro** —en `front_2` la única llamada está comentada, [§35.2](../05-codigo-muerto-y-roto.md)—
> sin nadie a quien preguntarle qué debía guardar.

Lo que sí queda cerrado: **no escribe nada** —reventar antes de la consulta es lo
único bueno que tiene—, la validación de `id` funciona (422 antes de reventar), y
**el guard que arregló la §35.2 sigue bien puesto**: comprobado con personal que
no es ni superusuario ni Secretario, que recibe 403.

### Y las otras tres escrituras del PIAR

Al mismo cuerpo incompleto contestan cada una una cosa distinta, y las cuatro
juntas son la tabla:

| Ruta | Con el cuerpo incompleto |
|---|---|
| `piars-actas-acuerdo/document` | **422** con los campos que faltan |
| `piars-asignaturas/field` | **400** `{"error":"Invalid"}` por lista blanca |
| `piars-grupos/contexto-de-grupo` | **200 `{"piars": 0}`** — el número de filas tocadas |
| `piars-config/config` | **500** |

El 200 de las dos de en medio **es el contraejemplo honesto** de las cuatro rutas
de ordinales de la [§87](b.md): allí «Cambiado» sin haber tocado nada, aquí un
recuento que se puede creer. La diferencia es una línea, y está en el lado bueno.

Fijado además que **las dos que guardan HTML del editor lo limpian**: el cliente
lo pinta como HTML y lo que no pase por el limpiador se ejecuta en la sesión de
quien abra el PIAR.

---

## §103 — El alta de un membrete, y las dos claves de la misma imagen

**Con el cuerpo vacío escribe la fila y contesta 201.** `config_certificados` no
tiene ninguna columna `NOT NULL`, así que el `INSERT` con todo en null pasa. Es
exactamente lo que le pasaba a `contratos`, el único de los nueve catálogos de la
[§78](../05-codigo-muerto-y-roto.md) que escribía basura, y por la misma razón:
**lo que impide que los demás escriban no es el código, es el esquema.**

> Lo que esto añade a la §78 no es otro ejemplo. Es que **aquella se cerró sobre
> nueve rutas de catálogo y `certificados/store` no era una de ellas.** La serie
> está agotada **para su población**, no para el patrón. Es la misma lección que
> la papelera ([§76](../05-codigo-muerto-y-roto.md)), y la tercera vez esta noche.

Lo que deja detrás es **un membrete sin nombre en el desplegable** desde el que se
elige el papel de un certificado — comprobado que sale ahí, no que se queda
escondido. Se mide y se anota: ponerle una validación es visible en dieciséis
colegios y hoy nadie ha medido qué manda el front al crear.

### Crear y editar no llaman igual a la misma imagen

`postStore` lee `encabezado_img_id` y `piepagina_img_id`; `putUpdate` lee
`encabezado_img` y `piepagina_img`. **Las dos escriben en la misma columna.**

Y las consecuencias **no son simétricas**, que es lo que lo hace caro:

- en el alta, la clave que no toca **no pone imagen**;
- en la edición, la clave que no toca **la borra**, porque `putUpdate` tiene un
  `else { = null }` que el alta no tiene.

O sea que un cliente que mande el mismo objeto a las dos rutas **crea el membrete
con imagen y se la quita al primer guardado**.

`ConfigCertificadosTest::test_editar_sin_imagen_la_borra_y_es_a_proposito` ya
fijaba la segunda mitad. Lo que faltaba era la primera, y con ella el porqué:
**«sin imagen» puede ser «con la clave que usa la otra ruta»**.

Y una tercera: si la imagen llega **como id suelto** en vez de como objeto, 500
«Trying to access array offset on int». La forma que espera la ruta no está
escrita en ninguna parte y no la valida nadie. No escribe.

---

## §104 — Los dos gemelos de la §74, y la ruta que no puede funcionar

### Los interruptores: la familia queda medida entera

La [§74](../05-codigo-muerto-y-roto.md) midió `para_alumnos` **abriendo el examen
con un token de alumno** y encontró que no cierra nada. Los dos de este lote son
sus gemelos, y la pregunta era la misma. La respuesta también:

| Interruptor | ¿cierra algo a quien nombra? |
|---|---|
| `para_alumnos` (§74) | **no** |
| `para_acudientes` | **no** — el acudiente sigue abriendo la actividad |
| `para_profesores` | **no** — el profesor también |

Los tres esconden en el listado del profesor (`actividades/compartidas` y la
pantalla de corregir) y **no cierran ninguna puerta**. Lo que decide es
`in_action`, y eso ya está medido.

> **Que la respuesta coincidiera no la hacía predecible**, y por eso había que
> medirla: `exigirQueLaActividadLeCorresponda` trata `Alumno` y `Acudiente` en la
> misma rama, pero **al profesor lo deja salir antes por otro camino** —el
> `return` temprano para todo el que no es familia—, así que las tres podían salir
> distintas. Lo que se confirma es la forma, no el valor.

**Se fija y no se juzga**, por lo mismo que su hermana: hacer que cierren es una
línea, y esconde de golpe actividades que hoy se ven en dieciséis colegios.

Y una asimetría más entre módulos: estos conmutadores, **sin el valor, apagan**
(`input()` sin defecto → null → 0); los de votaciones, que son el mismo patrón,
**encienden** (§101). Ninguno está mal por sí solo; **lo que no existe es un
criterio**.

### `mis-actividades/guardar`: 500 por una columna que no existe

Es **una copia de `actividades/guardar`** —los trece campos, en el mismo orden,
con las mismas cuatro líneas alrededor— con **uno cambiado**: `tipo_calificacion`
por `puntaje_por_promedio`, que **no es una columna de `ws_actividades`**. El
modelo ya lo tiene anotado como lo que es: «el puntaje calculado al resolver la
actividad», un atributo que el código le cuelga al objeto para armar la respuesta.

Eloquent no distingue: mete el nombre en el `UPDATE` y MySQL contesta
`Unknown column 'puntaje_por_promedio' in 'field list'`. **La petición cae
entera**, así que no escribe nada — ni siquiera la descripción, que sí venía.

> **No se arregla, y aquí el arreglo evidente es peor que el fallo.** El nombre
> bueno no se puede deducir: si fuera `tipo_calificacion` —lo que dice su
> hermana—, esta ruta pasaría a escribir trece campos que hoy no escribe, **y con
> el criterio de su hermana, que es vaciar lo que no venga en el cuerpo**
> (13-actividades §1). Convertir un 500 ruidoso en un vaciado silencioso de
> exámenes en dieciséis colegios no es un arreglo.

De las dos hermanas, **una revienta sin escribir y la otra escribe de más**. Queda
un test fijando que siguen siendo dos comportamientos distintos: si algún día
coinciden, es que alguien tocó una de las dos.

---

## Lo que aprendió este lote y no estaba escrito

1. **Un dato que no se reproduce aislado todavía no es un dato.** El mismo
   interruptor dio «no hace nada» y «se enciende solo» con el mismo código: la
   diferencia era que la fila de prueba estaba en la papelera. Hasta que cada
   llamada no tuvo su propio test, había dos observaciones y ningún resultado.
2. **Dos endpoints rotos desde el primer día, los dos por copiar-y-pegar, los dos
   invisibles al leerlos.** La única señal que los delata es llamarlos. Nada en
   el fichero se ve mal; el bloque copiado tiene sentido en el sitio del que
   viene.
3. **La lección de la población, por tercera vez esta noche.** La §78 se cerró
   sobre nueve rutas y `certificados/store` no era una; la §74 se cerró sobre un
   interruptor de tres. Ninguna de las dos estaba mal: estaban **cerradas sobre su
   población**, y nadie lo había escrito.

---

## PARA JOSETH

1. **¿Qué debía guardar `piars-config/config`?** Está enrutada, no la llama nadie
   y no puede funcionar. Con la respuesta se escribe; sin ella, reescribirla es
   inventar. (§102)
2. **¿Qué campo quería guardar `mis-actividades/guardar`?** Ídem, y con el aviso
   de arriba: **si la respuesta es `tipo_calificacion`, arreglarla enciende el
   vaciado de exámenes**, así que las dos cosas van juntas o ninguna. (§104)
3. **¿Deben `para_acudientes` y `para_profesores` cerrar de verdad?** Hoy no
   cierran nada. Es la misma pregunta que ya tienes abierta para `para_alumnos`,
   y ahora está medida sobre los tres. (§104)
4. **¿`certificados/store` debe exigir nombre?** Hoy un cuerpo vacío deja un
   membrete sin nombre en el desplegable de papeles del colegio. (§103)
5. **¿`set-votan-profes` debe exigir el campo?** Hoy, sin él, abre el voto a los
   profesores. Es la gemela de `set-locked`, que ya decidiste dejar como está — si
   la respuesta es la misma, no hay nada que hacer, pero conviene decidirlo con las
   seis delante y no de una en una. (§101)

## PARA OTRO LOTE

- **`certificados/update` vs `certificados/store`** (este lote, ya anotado) — las
  dos claves de la imagen. Si alguien unifica, que mire el `else` que solo tiene
  una de las dos.
- **Huecos del seed** (para quien lo regenere): `ws_actividades`,
  `piars_asignaturas` y `piars_grupos` **están vacías**. Los tests de este lote se
  fabrican sus filas, como `BitacorasTest` (§88).

## Lo que se nota en un colegio

**Nada.** Este lote no toca `app/`: las doce rutas quedan exactamente como
estaban. Lo que cambia es que ahora hay treinta tests que caen si alguna se
mueve — y dos rutas cuyo 500 está escrito con su mensaje exacto, para que el día
que alguien las toque se vea si ya escriben.

## Migraciones

**Ninguna.** El esquema no se toca.
