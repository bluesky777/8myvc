# Lote G — Los 44 interruptores que en el backend no decide nadie

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/g`, rama `fix/lote-g-interruptores`, base
> `simonbolivar_testing_g`.
>
> Secciones asignadas del 05: **§105–107**. Este lote **no es dueño de ningún
> controlador**: su entrega es este documento y sus tests. Lo que haya que
> arreglar en un controlador se anota para el lote dueño.

La pregunta: **de las 44 columnas `tinyint(1)` que en el backend no decide nadie,
¿las mira algún cliente?** Y la segunda, que el
[15](../15-la-noche-en-paralelo.md) deja escrita al lado: **las 48 que ni se
nombran, ¿llegan al cliente por un `SELECT *`?**

Las dos están contestadas, y **las dos ya lo estaban** desde el 21 de agosto en
la [§17 del 12](../12-larastan-nivel-7.md). Lo que este lote añade no es el
número: es **por qué nadie lo sabía**, y **contra qué se midió**.

---

## §105. El número no cambió; lo que estaba mal era la cabecera de la herramienta

Corrido esta noche contra los tres clientes, desde el árbol `g`:

```
  columnas tinyint(1) distintas ... 157
  ni se nombran .................. 48
  NO DECIDEN NADA ................ 44
  alguien decide con ellas ....... 65

  SIN NADIE QUE LAS MIRE, ni aquí ni allí: 49
```

Idéntico a la §17 del 12, que lo midió el 21 de agosto. **Y sin embargo la
cabecera del propio script dice otra cosa:**

```python
Con `--clientes` la salida separa lo que un cliente sí mira de lo que no mira
nadie. El 21 ago 2026, con los tres delante, quedaron **dos** columnas que no
aparecen ni en el backend ni en ningún cliente: `users.can_ask` y
`matriculas.profes_editar_notas`. Ver docs/migracion/12-larastan-nivel-7.md §17.
```

**Dos, y son cuarenta y nueve.** El documento que la propia cabecera cita dice
49 en un bloque de código, y luego dedica sus tres párrafos finales a `can_ask` y
a `profes_editar_notas` porque son **las dos que tienen algo que contar** — una
está encendida en las 2.351 cuentas, la otra es la hermana por matrícula de una
bandera que espera decisión. La cabecera se llevó **los dos ejemplos** y los
escribió como si fueran **el resultado**.

`git log` del script: **un solo commit**, nunca tocado. Así que no es que el
número haya cambiado: la cabecera nació diciendo eso.

> **Dos suena a «no hay nada»; cuarenta y nueve es una lista.** Y lo que se lee
> antes de correr una herramienta es su cabecera, no el documento que cita.

Es la tercera vez esta noche que lo que apaga una pregunta no es un detector
callado sino **un renglón que dice «ya está»** —las otras dos están en
[§89 y §90](c.md)—, y ésta es la variante más cara de las tres: las otras
dos vivían en tablas de un documento, y ésta vive **dentro del instrumento**, en
el sitio que se lee justo antes de decidir si vale la pena correrlo.

**Corregida la cabecera** con el número que da el propio script y con los dos
ejemplos nombrados como lo que son.

---

## §106. Contra qué se midió, que es lo que hace afirmable la frase

«No lo lee nadie, en ninguna parte» sólo vale lo que valen los ficheros que se
miraron. El 15 lo dice sin rodeos: *un grep de clientes vale lo que valen los
ficheros que mira, y «no lo manda nadie» es la afirmación más fácil de hacer con
una muestra incompleta, porque nada a la vista la contradice.*

Así que antes de repetir el 49, se midió **qué se queda fuera del barrido**.

### 106.1 Dos huecos en el barrido de clientes, medidos

| Hueco | Qué deja fuera |
|---|---|
| `EXTENSIONES = ('.js', '.ts', '.html', '.dart', '.vue')` | **30 ficheros `.mjs`** de `myvc_front`, más `.scss`, `.json`, `.md`, `.xml` |
| `if fichero.stat().st_size > 1_000_000: continue` | **todo bundle construido**, que es justo donde vive el código que corre |

Los dos se comprobaron uno a uno con las 49 columnas delante:

- **En los ficheros que la extensión deja fuera** (`.mjs`, `.scss`, `.json`,
  `.md`, `.xml`, `.yaml` de los tres clientes): **ninguna de las 49 aparece.**
- **En el bundle**: ver abajo, y es lo que más aporta.

### 106.2 El corpus que la herramienta no puede leer, y que sí contesta

`../myvc_dist` es un repositorio hermano con un **bundle construido** de
3.736.964 bytes —`assets/index-DWlqUB0R.js`, del 21 ago 17:45—, y sus mensajes de
commit siguen las fases de la migración del front («la fase 10 entera —
TypeScript en los 248 ficheros»). El script **no lo lee nunca**: pesa 3,7 veces
el límite de un mega, y ese límite está puesto con una razón escrita —«un
minificado de un mega no dice nada que no diga su fuente»— que es cierta cuando
la fuente está entera delante.

Se buscaron ahí las 49, **con control**:

| Buscado en el bundle | Resultado |
|---|---|
| Las **49** columnas sin lectores | **0 apariciones** |
| Control: `mostrar_puesto_boletin`, `mostrar_nota_comport_boletin`, `caritas`, `perdido`, `presencial` | **aparecen las cinco** |

El control es lo que convierte el cero en una medición: si el bundle no fuera
legible —minificado, comprimido, binario para `grep`— las cinco de control
habrían salido a cero también, y el cero de las 49 no habría significado nada.

O sea que **el 49 se sostiene contra un cuarto corpus que la herramienta no puede
ver**, y por un camino independiente del suyo: el código fuente y el artefacto
construido dicen lo mismo.

### 106.3 El arreglo no es leer el bundle: es decir que no se ha leído

Pasarle `../myvc_dist` como cuarto cliente **no cambia el número** —siguen siendo
49— porque el fichero que importa sigue pasando del mega y se salta. Lo que sí
cambia desde esta noche es que **el script lo dice**:

```
  NO LEÍDOS por pasar de 1 MB (1). Si la pregunta es «esto no lo
  mira nadie», hay que mirarlos a mano, y con columnas de control:
       3,736,964 B  /Users/…/myvc_dist/assets/index-DWlqUB0R.js
```

Subir el límite habría sido el arreglo equivocado: un bundle minificado dentro
del mismo `grep` que el código fuente ensucia la respuesta de las 108 columnas
que sí tienen lectores, y no arregla el problema de fondo, que es que **nadie
sabía que había algo sin leer**. Un barrido que encoge en silencio se lee igual
que uno que no encontró nada — la misma familia que el `| head -5` que dijo cinco
donde había seis.

Y el otro hueco, el de las extensiones, sí se cerró donde era barato:
**`.mjs` entra en `EXTENSIONES`** —`myvc_front` tiene 30— después de comprobar
que ninguna de las 49 aparecía en ellos, para que el cambio no se justifique a sí
mismo.

**Lo que esto no dice**, y no se afirma: que `myvc_dist` sea lo que está
desplegado hoy en los dieciséis colegios. No lo nombran ni `DESPLIEGUE.md` ni
`DESPLIEGUE-REFERENCIA.md`. Queda como pregunta abajo.

---

## §107. La segunda pregunta: sí llegan al cliente por un `SELECT *`, y son 22

De las **48 columnas que el backend ni nombra**, hay **22 que un cliente sí
mira**, y todas son de la misma tabla —`antecedentes`— y llegan por la misma
puerta:

```php
// app/Http/Controllers/Matriculas/EnfermeriaController.php:43
$consulta = 'SELECT * FROM antecedentes WHERE alumno_id=?';
```

Son los antecedentes médicos del alumno: siete `fami_*` (antecedentes
familiares), ocho `patol_*` (patologías) y siete de vacunación, `varicela`
incluida. `myvc_front` las pinta y las guarda —el guardado va por
`EnfermeriaController`, campo a campo, con `ColumnaSegura`—, y el backend **no
las nombra en ningún sitio**: existen en el esquema, viajan por el `*` y sólo
tienen sentido en la pantalla.

> **Una columna que el backend no nombra no es una columna muerta.** Es lo que
> este lote tenía que contestar, y la respuesta es que **el `SELECT *` es una
> puerta de verdad**: 22 de 48. Buscarlas en `app/` da cero apariciones, y con
> ese cero delante la conclusión natural —«esto no lo usa nadie, se puede
> borrar»— habría borrado la ficha médica de los alumnos.

Las **26 restantes** de ese montón no las mira nadie tampoco en los clientes, y
son las que la §17 ya explicó una a una: las veinte `per{1..4}_*` de
`df_alumnos` / `df_asignaturas` (la copia denormalizada de las definitivas que
alguien empezó y no terminó, **cero filas**), las de `default_unidades` /
`default_subunidades` (**cero filas**), las firmas de `dis_procesos` y los
`*_accepted` de `change_asked_assignment`.


---

## PARA JOSETH

### 1. ¿Qué es `../myvc_dist` y quién lo despliega? (§106.2)

Es un repositorio hermano con un bundle construido de 3,7 MB cuyos commits siguen
las fases de la migración del front. **Ningún documento de despliegue lo nombra.**
La pregunta importa más allá de este lote: si es lo que corre en los colegios,
entonces **es un quinto sitio que consume la API** y ninguna medición de «esto no
lo lee ningún cliente» lo ha mirado nunca —ni ésta, hasta esta noche, y a mano—.
Si es solo un artefacto de trabajo, no hace falta hacer nada.

### 2. Las 49 columnas: qué se hace con ellas

Ya está contestado que **no las lee nadie**, y la [§17 del 12](../12-larastan-nivel-7.md)
ya midió que **casi ninguna tiene datos dentro**. No hay nada que arreglar. Lo
único que queda es una decisión de limpieza que no es de esta noche: `df_alumnos`,
`df_asignaturas`, `df_grupos`, `default_unidades` y `default_subunidades` son
**cinco tablas con cero filas y cero menciones**. Borrarlas es un cambio de
esquema —migración— y no lo pide nadie; dejarlas cuesta que la próxima persona
que mire `notas_finales` crea que hay una copia denormalizada que mantener.

## PARA OTRO LOTE

- Las 22 columnas de `antecedentes` que llegan por `SELECT *`
  (`EnfermeriaController:43`) no son de ningún lote de esta noche. **No hay nada
  que arreglar**: se anotan porque el cero de apariciones en `app/` invita a
  borrarlas y borrarlas apagaría la ficha médica.
