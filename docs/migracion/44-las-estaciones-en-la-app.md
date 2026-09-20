# Las estaciones de matrícula, atendidas desde la app

**Todo este documento es propuesta.** Escrito el **20 sep 2026** a partir del plan que ya
existe en el front —`myvc_front/INVESTIGACION-MATRICULAS.md` (el embudo, las plataformas y el
modelo de datos) y `myvc_front/PANTALLAS-MATRICULA.md` (las quince pantallas)— y de una
pregunta que aquel plan dejó sin contestar: **las estaciones del día de matrículas están
diseñadas para `app2`, o sea para la web, y quien atiende una estación es un docente de pie en
un aula con una fila delante.**

El diseño de las once pantallas de teléfono, con sus porqués, vive en
[`myvc_flutter/docs/estaciones.md`](../../../myvc_flutter/docs/estaciones.md).
**Esto es el contrato que necesitan y lo que cuesta.**
Maqueta navegable: https://claude.ai/artifact/3fixY3xaQsjGT2V4LAWPbE

---

## 1. Lo medido (20 sep 2026, sobre `main` en el árbol principal)

- **La lista de chequeo ya está a medio hacer**, y es la mejor noticia. En
  `database/schema/mysql-schema.sql`:

  ```
  requisitos_matricula   year_id, orden, requisito, descripcion, editable_por_profe_id, updated_by
  requisitos_alumno      alumno_id, requisito_id, estado DEFAULT 'Falta', descripcion, updated_by
  ```

  Catálogo por año + instancia por alumno + un responsable + un estado. Lo que le falta para
  ser una estación son cuatro columnas, y están listadas en `PANTALLAS-MATRICULA.md` §4.

- **Seis rutas** en `Matriculas/RequisitosController.php`, todas `auth.personal`.

- **La búsqueda de personas existe**: `PUT buscar/por-nombre` y `PUT buscar/por-apellido`,
  `auth.personal`. La pantalla de buscar no necesita ruta nueva.

- **El código del formulario y su QR existen**, con diez rutas escritas y probadas el 19 sep
  ([41](41-el-formulario-de-inscripcion.md)). La estación lee **ese** código: acuñar otro
  sería un segundo papel para la misma familia.

- **La app no puede recibir un push hoy.** `myvc_flutter/pubspec.yaml` tiene `firebase_core` y
  `firebase_analytics` y **no tiene `firebase_messaging`**. El lado del servidor sí está
  desplegado desde el 25 ago (`98e6311`), y **su disparo va cada quince minutos**: para avisar
  de notas nuevas es correcto, para una fila en un patio es no avisar.

---

## 2. El agujero que hay que tapar ANTES de escribir ninguna ruta

**`requisitos_alumno.estado` es un `varchar(255)` sin vocabulario cerrado, y `postAlumno`
escribe literalmente lo que venga en el cuerpo.** El defecto de la columna es `'Falta'` y lo
demás lo decide cada llamante; hay tres pantallas escribiendo ahí (`app2/persona-matriculas`,
`PersonaCtrl` de la vieja y la de prematrículas).

Toda la cola de una estación es la pregunta *«¿está cerrado el paso anterior?»*. **Esa
pregunta no se puede construir encima de una columna cuyo conjunto de valores no está
fijado**: el día que una pantalla vieja escriba `cumple` en minúscula, la persona **desaparece
de la fila de la estación siguiente** y nadie se entera — ni ella, que está esperando de pie.

Es exactamente la forma del fallo que esta misma tabla ya tuvo una vez: el `UPDATE` que
escribía `estado=NULL` cuando el cuerpo no lo mandaba, arreglado el 1 sep y contado en el
docblock de `postAlumno`. Aquél borraba en silencio y devolvía `'Actualizado'`.

**Lo que hay que hacer, y va primero:** fijar los estados, migrar lo escrito, y **rechazar** lo
que no esté en la lista en vez de guardarlo. Propuesta de conjunto —cuatro, no más—:

```
Falta       nadie lo ha tocado (el defecto de hoy, se conserva)
Cumple      cerrado
Observado   cerrado, con un texto que viaja al siguiente
Devuelto    no pasa, y `motivo_devolucion` NO puede estar vacío
```

---

## 3. El contrato — siete rutas

```
GET  estaciones                       (auth.personal)
  -> { campana:{year_campana, abierta}, estaciones:[ {nro, nombre, donde, rol, bloquea,
       puedo_atender, esperando} ], mi_estacion:nro|null }

GET  estaciones/{nro}/cola            (auth.personal + rol de la estación)
  -> { nro, nombre, al_dia_at, cola:[ {alumno_id|aspirante_id, nombres, apellidos, grupo,
       llego_at, avisos:[…], devuelto_antes:bool} ] }

GET  estaciones/huella                (auth.personal)
  -> { por_estacion: { "1":{n, ultimo_cambio}, "2":{…} } }        ~300 bytes

GET  estaciones/alumno/{id}           (auth.personal)
GET  estaciones/codigo/{codigo}       (auth.personal)
  -> { persona:{…}, acudiente:{…}|null, codigo, pasos:[ {nro, nombre, estado, cerrado_por,
       cerrado_at, motivo, obligatorio, bloquea, mio:bool, requisitos:[…] } ],
       puede_atenderlo:bool, si_no:{devolver_a_nro, donde} }

PUT  estaciones/{nro}/marcar          (auth.personal + rol de la estación)
  { alumno_id|aspirante_id, resultado: cumple|observado|devuelto, motivo?, requisitos:[…] }

PUT  estaciones/{nro}/enviar-a/{destino}   (auth.personal + rol de la estación)
  { alumno_id|aspirante_id }     registra el intento, NO escribe el paso
```

### 3.1 · Por qué la huella es una ruta y no un `ETag`

Es el caso de [34](34-la-huella-de-sincronizacion.md), otra vez y por el mismo motivo: la
pantalla de la estación tiene que preguntar *«¿ha llegado alguien?»* cada veinte segundos
durante ocho horas, y **ninguna de las lecturas de arriba manda `ETag` ni `Last-Modified`**, así
que hoy preguntar barato no se puede. La huella devuelve `(cuántos, último cambio)` por
estación; **las dos hacen falta**, porque `updated_at` no ve que alguien salió de la cola y el
conteo sí.

Y la regla dura de aquel documento se hereda entera: **se calcula sobre lo que devuelve la
cola, no sobre la tabla.** Una huella de `requisitos_alumno` se movería con lo que esta
estación no ve, y podría no moverse con lo que sí.

Con diez estaciones abiertas una jornada son unas 14.400 peticiones de 300 bytes. Pedir la
cola entera cada veinte segundos serían las mismas peticiones multiplicadas por cien.

### 3.2 · El permiso se parte en dos, como en las colillas

**Las lecturas las hace cualquiera del personal.** Quien atiende Documentos es un docente, y
cuando una mamá pregunta *«¿mi hija en qué va?»* tiene que poder mirarlo sin pedirle el
teléfono a secretaría. Es el mismo argumento que dejó las dos lecturas del formulario abiertas
a todo el personal ([41](41-el-formulario-de-inscripcion.md) §12).

**Las escrituras piden el rol de esa estación**, dentro del método, y contestan **403** al
resto. `auth.personal` deja pasar a cualquier docente, y cerrar un paso decide si una familia
sigue en la fila o se va a su casa. Es la forma de `plantilla-notas/`: guard en la ruta,
permiso dentro.

> **Y aquí hay una decisión que no puede tomar el backend solo**: la tabla de hoy tiene
> `editable_por_profe_id`, que es **una persona**, y el plan del front dice **rol**. El
> docente que atiende Documentos el martes no es el del miércoles. Mientras eso sea una
> persona, la estación tiene un dueño y no un turno. Va a la §5.

### 3.3 · `enviar-a` no escribe el paso, y eso es lo que la hace útil

El que llega a la 4 sin haber pasado por la 3 **no deja marca en el paso 4**: se registra el
intento. Así el tablero del rector puede decir *«en la 4 se presentan doce sin pasar por la 3»*
—el cartel está mal puesto, o la 3 está tapada— **sin ensuciar el recorrido de esa familia**
con un paso que no ocurrió.

---

## 4. Lo que mueve, contado y no supuesto

**Siete rutas nuevas**, todas con `auth.personal` **declarado en la ruta** y una familia nueva
(`estaciones/`). Con las **615** que este árbol cuenta hoy, el router quedaría en **622** —y
ese número **se cuenta con `route:list --json` en el árbol principal después de fundir**, no se
suma aquí: es la regla de `CLAUDE.md` y ya se ha roto tres veces.

**Mueve tres instantáneas:**

| | por qué |
|---|---|
| `rutas.json` | siete rutas nuevas |
| `guards-por-ruta.json` | las siete llevan guard |
| `guard-por-familia.json` | familia nueva: `estaciones: 7 de 7` |

**Y NO mueve la cuarta, que es lo que hay que comprobar y no suponer.**
`familias-que-nunca-entran-en-el-candado.json` lista las familias con **menos de dos hermanas
con guard**, y ésta entra con siete de siete: el candado de consistencia por familia **sí la
mira**. Por lo mismo tampoco se mueve
`FamiliasQueNuncaEntranTest::test_cuantas_escrituras_viven_donde_el_candado_no_llega`, que
sigue en **26**: las dos escrituras nuevas (`marcar` y `enviar-a`) viven en una familia que el
candado **sí** recorre.

**Tampoco se mueve `RutasPreLoginTest::TOTAL_PUBLICAS`**: ninguna de las siete es pública, y
no puede serlo. Quien atiende una estación tiene cuenta —es personal del colegio— a
diferencia de la familia del aspirante, que es lo que obligó a abrir las tres del formulario.

**Y una tanda de migración**, que va antes que las rutas:

```
requisitos_matricula  + tipo, rol_id, obligatorio, bloquea, estacion_nro, dias_limite
requisitos_alumno     + aspirante_id, cerrado_por, cerrado_at, motivo_devolucion
                      + el vocabulario cerrado de `estado` (§2)
```

Son **columnas anulables sobre dos tablas pequeñas**, así que en MariaDB 10.5 entran al
instante — la misma medida que dejó escrita la tanda de `notas`. Lo que **no** es gratis es el
§2: migrar lo escrito exige mirar los dieciséis colegios uno a uno.

---

## 5. Lo que espera una decisión de Joseth

1. **¿Estación con rol o estación con persona?** (§3.2). Es la que bloquea el permiso de las
   dos escrituras, y no tiene una salida barata: con `editable_por_profe_id` tal como está, o
   la estación tiene un dueño fijo todo el día, o la escritura se abre a todo `auth.personal`
   — que es justo lo que este documento dice que no.
2. **¿Se le avisa al acudiente en cada estación, o solo cuando lo devuelven?** Cinco avisos en
   una mañana es spam; uno solo cuando algo sale mal puede llegar tarde.
3. **¿El push inmediato por estación entra ahora o después?** No bloquea nada —la cola con
   huella basta para el día de matrículas— pero si entra, **no puede ir por la tanda de los
   quince minutos** y el lado Flutter de `notificaciones.md` hay que empezarlo.
4. **Las siete rutas, con el precio delante.** Una familia nueva entra **entera en un
   commit** —a trozos el censo la recogería mal— así que esto se autoriza de una vez o no se
   empieza.
