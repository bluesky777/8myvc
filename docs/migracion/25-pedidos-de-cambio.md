# 25. Los pedidos de cambio — la tabla estrecha

> **Estado: diseño, 2 sep 2026. Nada construido.** Lo decidió Joseth ese día, junto
> a los recortes del panel ([24](24-el-panel-de-inicio.md)) y **separado de ellos a
> propósito**: los pedidos son 0,5 KB de los 274 KB de la respuesta del panel y una
> consulta de las 39. Rediseñarlos no le quita un milisegundo a esa pantalla, y
> juntar las dos cosas en un lote haría que la lenta arrastrara a la rápida.
>
> Lo que sigue es la forma propuesta, lo que cuesta llegar a ella y las tres
> preguntas que hay que contestar antes de escribir una migración.

---

## 1. Qué es esto, para el que llega

Un **pedido de cambio** es cómo alguien que no puede editar un dato pide que se lo
corrijan: una familia que ve mal escrito el apellido de su hijo, un docente que
quiere que le quiten una asignatura del año o que le cambien la foto. El pedido
queda esperando, y quien revisa lo ve en una bandeja: el panel de inicio del
superusuario (`getToMe`, [24](24-el-panel-de-inicio.md)).

No es un módulo grande —seis rutas de `ChangeAskedController`, tres de
`ChangeAskedAssignmentController`— pero **es la única puerta por la que un dato
oficial cambia sin que lo teclee quien tiene el permiso**, y por eso el rastro que
deja importa más que su tamaño.

---

## 2. Lo que hay, medido y no leído

Tres tablas, y las cifras son de la copia del contenedor el 2 sep 2026:

| tabla | forma | filas |
|---|---|---:|
| `change_asked` | la cabecera: quién pide, a quién, sobre quién, y dos punteros —`data_id`, `assignment_id`— | **104**, de ellas **6 sin contestar** |
| `change_asked_data` | **54 columnas**: un par `campo_new` / `campo_accepted` por cada campo de la ficha del alumno | |
| `change_asked_assignment` | **19 columnas** del mismo estilo, para notas, frases y asignaturas | |

### 2.1. De las 31 columnas `_new`, **seis** se escriben

Contado sobre `app/`, sin los modelos —que sólo llevan las `@property` generadas
desde el esquema—:

| columna | la escribe |
|---|---|
| `nombres_new`, `apellidos_new`, `sexo_new`, `fecha_nac_new` | `ChangeAskedController::crear_o_modificar_datos_de_pedido` |
| `image_id_new`, `foto_id_new` | `Perfiles\ImagesUsuariosController` |
| *(`image_to_delete_id`, que no lleva `_new`)* | `Perfiles\ImagesController` |

Se leen dos más —`firma_id_new` y `pazysalvo_new`— que **no las escribe nadie**. Las
**23 restantes** —documento, eps, teléfono, celular, dirección, barrio, estrato,
religión, email, facebook, las tres ciudades, tipo de documento, tipo de sangre…—
están en la tabla, tienen su `_accepted` al lado, algunas tienen **clave foránea e
índice**, y **ninguna se ha escrito nunca desde este código**.

Lo mismo en `change_asked_assignment`: de sus 18 columnas de datos, viven
`nota_id`/`nota_new`, `asignatura_to_remove_*`, `materia_to_add_*`/`grupo_to_add_id`
y `creditos_*`. Las de comportamiento —`nota_comport_*`, `defini_comport_*`— y
`frase_asignat_*` están muertas.

> **Y eso cambia el tamaño del problema en la dirección buena.** «Migrar 54
> columnas» suena a semana; **migrar seis campos, y 104 filas, es una tarde**. La
> medición no era un adorno: era lo que decidía si esto se podía hacer.

### 2.2. Cómo se escribe un pedido hoy

`crear_o_modificar_datos_de_pedido()` ([línea 1081](../../app/Http/Controllers/ChangeAskedController.php#L1081))
tiene el mismo bloque repetido cuatro veces —uno por campo— en dos ramas, y la rama
de creación **inserta una fila, se llama a sí misma y vuelve a entrar**, apoyándose
en `DB::getPdo()->lastInsertId()` para enterarse de qué fila acaba de crear. Añadir
un quinto campo es copiar dos bloques más y confiar en que la recursión siga
terminando.

---

## 3. Los tres problemas, en orden de gravedad

1. **No queda el valor viejo.** Un pedido guarda `nombres_new`, no `nombres_old`.
   Cuando se aprueba, la ficha se sobreescribe y **lo que había desaparece**: no se
   puede contestar «¿qué decía antes?» ni «¿quién autorizó qué?» mirando el pedido.
   El rastro está en `bitacoras`, que es otra tabla y otra pregunta.
2. **Sólo se puede pedir sobre un alumno o una asignatura.** La cabecera tiene dos
   punteros con nombre propio, así que la pregunta *«¿y si un docente quiere
   corregir su propio apellido?»* no tiene dónde escribirse.
3. **Cada campo nuevo de la ficha son dos columnas más**, en los quince colegios,
   más su bloque de código y su `if` en dos fronts. Es la razón por la que hay 23
   columnas muertas: se añadieron por si acaso y nadie las conectó.

Y uno que no es de forma pero vive aquí: **`change_asked` se borra con `DELETE`, no
con `deleted_at`**. Un pedido retirado no deja rastro en ninguna parte — está escrito
en la §49 del [05](05-codigo-muerto-y-roto.md), que le puso guarda a quién puede
retirarlo pero no le devolvió el rastro.

---

## 4. La forma propuesta: una cabecera y una fila por campo

```sql
CREATE TABLE `pedidos` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `year_id`       int unsigned NOT NULL,   -- el pedido es de un año, como todo aquí
  `pedido_por`    int unsigned NOT NULL,   -- users.id
  `entidad`       varchar(30)  NOT NULL,   -- 'alumno' | 'profesor' | 'asignatura'
  `entidad_id`    int unsigned NOT NULL,   -- la fila de esa entidad
  `estado`        varchar(20)  NOT NULL,   -- 'pendiente' | 'aceptado' | 'rechazado' | 'retirado'
  `comentario`    varchar(255) DEFAULT NULL,
  `resuelto_por`  int unsigned DEFAULT NULL,
  `resuelto_at`   datetime     DEFAULT NULL,
  ...
  KEY (`year_id`, `estado`),              -- la consulta de la bandeja
  KEY (`entidad`, `entidad_id`)
);

CREATE TABLE `pedido_campos` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id`   int unsigned NOT NULL,
  `campo`       varchar(40)  NOT NULL,    -- 'nombres', 'foto_id', 'creditos'
  `valor_viejo` text         DEFAULT NULL,-- lo que decía cuando se pidió
  `valor_nuevo` text         DEFAULT NULL,
  `estado`      varchar(20)  NOT NULL,    -- se acepta campo a campo, como hoy
  ...
  KEY (`pedido_id`)
);
```

Qué resuelve cada pieza, y no es simetría por gusto:

- **`entidad` + `entidad_id`** quitan los dos punteros con nombre. Un pedido sobre un
  docente no necesita una tabla nueva.
- **`valor_viejo`** es lo que hace auditable la aprobación, y es la única columna que
  hoy no existe en ninguna forma.
- **`estado` por campo** conserva lo que ya hace el código: hoy se acepta campo a
  campo con los `_accepted`, y eso **no se toca** — es comportamiento del colegio, no
  una casualidad del esquema.
- **`campo` como texto** es lo que quita las columnas. El precio es que el servidor
  ya no tiene una foránea que le impida escribir `'nombrez'`: la lista de campos
  permitidos pasa a ser una constante en PHP, comprobada al crear el pedido. Es un
  cambio real y hay que decirlo: **se cambia integridad de esquema por flexibilidad**,
  y la mitad que lo hace aceptable es que el conjunto de campos hoy son **seis**.

### 4.1. Lo que el servidor podrá comprobar y hoy no

- Que el campo pedido **existe y es pedible** (lista blanca por entidad).
- Que el que pide **puede pedir sobre esa entidad** — hoy el `alumno_id` sale de la
  fila y está bien, pero el criterio vive repartido en tres métodos.
- Que no hay **dos pedidos vivos** del mismo campo sobre la misma fila, que hoy es lo
  que `verificar_pedido_actual` hace a medias.

---

## 5. El traspaso de lo que ya hay

104 filas en este colegio, seis campos vivos. La migración copia, no interpreta:

| de | a |
|---|---|
| `change_asked.id`, `asked_by_user_id`, `year_asked_id` | `pedidos` |
| `answered_by` + `accepted_at` / `rechazado_at` | `estado` + `resuelto_por` + `resuelto_at` |
| cada `campo_new` no nulo de `change_asked_data` | una fila de `pedido_campos` |
| `campo_accepted` | `pedido_campos.estado` |
| **`valor_viejo`** | **`NULL`**, y así se queda |

Esa última fila es la que hay que leer dos veces: **el valor viejo de los pedidos
históricos no existe y no se puede inventar**. Las 104 filas migradas nacen sin él, y
las nuevas lo tendrán. Rellenarlo con la ficha de hoy sería peor que dejarlo vacío:
la ficha ya cambió.

**Las tablas viejas no se borran en la misma migración.** Se quedan hasta que el
último colegio esté desplegado y la bandeja nueva haya trabajado un periodo — es la
misma regla con la que se maneja aquí todo lo que tiene quince copias.

---

## 6. El orden de despliegue, que es la parte que decide si esto se puede hacer

Tres clientes tocan esta familia:

| cliente | qué hace con los pedidos |
|---|---|
| aplicación vieja (AngularJS, **quince colegios**) | pide (`solicitar-cambios`) y revisa (el modal de `AnunciosDir`) |
| `app2` | la pantalla `panel/peticiones` |
| `myvc_flutter` | **nada** — lee la clave `alumnos` de `to-me`, pero para los acudidos |

O sea que la aplicación vieja **pide y revisa**, y es la que menos se toca. El orden
que funciona es el de siempre en este repo, y no admite atajos:

1. Backend: tablas nuevas + escritura **doble** (se escribe en las dos formas) y
   lectura de la vieja. Nadie nota nada.
2. Los dos fronts pasan a la forma nueva, colegio a colegio.
3. Backend: se deja de escribir la vieja. Las tablas viejas se quedan quietas.
4. Cuando el último colegio lleva un periodo sin usarlas, se borran.

**El paso 1 es el que hace que esto sea posible**, y es también el que más código
tiene: sin escritura doble, cada colegio queda roto entre su despliegue de backend y
el de su front.

---

## 7. Lo que falta decidir, y no lo decide quien escriba el código

1. **¿Cuántos pedidos vivos hay en los quince colegios?** Aquí hay 6 pendientes de
   104 históricos. Si el mecanismo está muerto en trece colegios, la respuesta buena
   puede ser **retirarlo** en vez de rediseñarlo, y esa conversación es de Joseth.
   Es una consulta de una línea por colegio y va **antes** que la migración.
2. **¿Qué entidades y qué campos entran en la lista blanca?** Hoy son seis campos de
   alumno. Abrirla a la ficha del docente es una función nueva, no un traspaso.
3. **¿Un pedido retirado deja rastro?** Hoy no (es un `DELETE`). La forma nueva lo
   permite —`estado = 'retirado'`— y hacerlo cambia lo que ve la bandeja: un pedido
   retirado **aparecería**, y hoy desaparece. Es decisión del colegio.
