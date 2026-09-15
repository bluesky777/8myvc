# 37 · Qué más escribe en un año que no es el suyo — el censo, no el arreglo

**Medido el 14 sep 2026** en `.worktrees/anios` sobre `b294ace` más el trabajo de
`ordinales`, con el script que va al final. Sale de cerrar los cuatro catálogos de
[16-escribir-en-un-anio-pasado.md](16-escribir-en-un-anio-pasado.md) y de una
pregunta que ese cierre dejó en el aire: **¿eran de verdad los únicos?**

> ## Lo que este documento es y lo que NO es
>
> **Es un censo, no una lista de fallos.** Cada fila de abajo es un método que
> escribe en una tabla con `year_id` sin comprobar de qué año es la fila. Eso es
> una **forma**, no una consecuencia. Y la diferencia no es retórica: **de las ocho,
> la mitad se cayó al medirla** — ver la §2.2, donde la hipótesis que parecía más
> grave del documento resultó no ocurrir.
>
> **No se ha cerrado ninguno**, por decisión: el encargo del 14 sep era medir y
> escribir. La decisión de qué hacer con cada uno es de Joseth y no está tomada.

---

## 1. La cifra que dije primero era de mi detector, y estaba mal

Publiqué **«42 rutas de escritura sin mirar el año»** a media tarde. **Son ocho.**

El primer detector contaba *«el método menciona la tabla»* y escribía
`UPDATE|DELETE|->update\(` en cualquier sitio del cuerpo. Con eso, un método que
**lee** `grupos` en un `SELECT` y escribe en otra tabla distinta contaba como
escritura sobre `grupos`; y un nombre de tabla **dentro de un comentario** contaba
igual que uno dentro de un `UPDATE`. En este repositorio, donde los comentarios
explican la consulta de al lado, eso no es un sesgo pequeño: es la mayoría del
ruido.

El segundo quita los comentarios antes de mirar y exige que el nombre de la tabla
esté **pegado al verbo que escribe** —`UPDATE <tabla>`, `INSERT INTO <tabla>`,
`DELETE FROM <tabla>`, `DB::table('<tabla>')->update|delete|insert`—. Resultado:

| | |
|---|---|
| rutas de escritura que tocan de verdad una tabla con `year_id` | **58** |
| de ellas, **sin** mencionar `year_id` en el método | **8** |

*El primer sitio donde mirar cuando el número sale raro es el detector.* Está
escrito en `CLAUDE.md` desde agosto y volvió a pasar hoy — y lo caro no es el 42:
es que **42 suena a trabajo de una semana y 8 suena a una tarde**, así que la cifra
mala no habría cambiado el arreglo, habría cambiado si alguien lo emprendía.

---

## 2. Las ocho, con lo que hay que medir en cada una

**`menciona year_id` no es «lo comprueba»**, y por eso las 50 restantes tampoco
están certificadas: sólo están fuera de este censo. Lo que separa una cosa de la
otra es leerlas, y aquí están leídas las ocho.

### 2.1 · Las dos de forma idéntica a las que se acaban de cerrar

| | |
|---|---|
| `PUT requisitos/update` | `Matriculas\RequisitosController::putUpdate` |
| `DELETE requisitos/destroy/{id}` | `Matriculas\RequisitosController::deleteDestroy` |

```php
$consulta = 'UPDATE requisitos_matricula SET requisito=?, descripcion=?, … WHERE id=?';
```

Id por el cuerpo o por la URL, `WHERE id=?` a secas, y `requisitos_matricula`
tiene `year_id`. **Es byte a byte la forma de `escalas/update`** antes del 14 sep.

**Lo que falta medir es la consecuencia**, que no es la misma: un requisito de
matrícula no se imprime en ningún boletín. Lo que cambia al editar el de 2023 es
**el registro de qué se le exigió a las familias aquel año**, que es papel de
secretaría y no de aula. Puede que a nadie le importe; puede que sea lo que hay
que poder demostrar en una auditoría. **No lo sé, y por eso no lo cierro.**

### 2.2 · Las cuatro del PIAR — **medidas, y la alarma se cae**

| | |
|---|---|
| `POST piars-alumnos/document` | `Piars\PiarsAlumnosController::postDocument` |
| `DELETE piars-alumnos/document/{alumno_id}` | `Piars\PiarsAlumnosController::deleteDocument` |
| `PUT piars-alumnos/field` | `Piars\PiarsAlumnosController::putField` |
| `PUT piars-grupos/contexto-de-grupo` | `Piars\PiarsGruposController::putContextoDeGrupo` |

**Esta sección decía otra cosa hace media hora, y se deja el cambio a la vista
porque el cambio es el hallazgo.** Escrita sobre la lectura del código sola, decía:
*«escriben con `WHERE alumno_id=?`, así que si un alumno tiene PIAR en varios años
una llamada las escribe todas — no hay “el año equivocado”, hay “todos los años”, y
esto es más grave que lo que se cerró hoy»*.

**Medido, no ocurre.** En la copia de desarrollo de `simonbolivar`:

```sql
SELECT COUNT(*) filas, COUNT(DISTINCT alumno_id) alumnos FROM piars_alumnos;
-- 65 filas, 65 alumnos  → una fila por alumno, ninguna repetida
```

O sea que `piars_alumnos` **tiene `year_id` pero en la práctica no es por año**: hay
un PIAR por alumno y punto. Con una fila por alumno, escribir «todas las de ese
alumno» y escribir «la suya» son la misma operación, y el agujero no existe.

**Dos matices que sí sobreviven**, y son de otra clase:

1. **Es de UN colegio.** 65 filas es poco, y que hoy haya una por alumno no es una
   restricción del esquema: `piars_alumnos` no tiene índice único por `alumno_id`.
   El día que un colegio cree el segundo PIAR de un alumno, la forma vuelve a
   morder — y **nada se pondría rojo**.
2. **`putField` sí filtra la columna, y mi nota anterior lo daba por comprobar.**
   Tiene lista blanca explícita —`['valoracion_pedagogica', 'ajustes_generales',
   'reporte']`— y además pasa el texto por `HtmlDelEditor::limpiar`, porque es HTML
   que el cliente pinta. **No es `ColumnaSegura` pero hace su trabajo**, y escribe
   con `WHERE id=?`, no con `alumno_id`. La afirmación de que las cuatro escriben
   por `alumno_id` era mía y era falsa: son tres.

*Lo que este apartado enseña no es sobre el PIAR: es que una forma peligrosa leída
en el código y una consecuencia medida en la base **no son la misma afirmación**, y
que de las dos la que se publica tiene que ser la segunda.*

### 2.3 · Las dos de disciplina

| | |
|---|---|
| `PUT nota_comportamiento/guardar-libro` | `NotaComportamientoController::putGuardarLibro` (`dis_libro_rojo`) |
| `PUT disciplina/cambiar-situacion-derivante` | `DisciplinaController::putCambiarSituacionDerivante` (`dis_procesos`) |

Las dos son la forma de siempre, leídas:

```php
UPDATE dis_libro_rojo SET <columna>=:valor WHERE id=:libro_id
UPDATE dis_procesos   SET become_id=?      WHERE id=?
```

*(El censo no encontró `DisciplinaController` a la primera porque vive en
`app/Http/Controllers/Disciplina/`, no en la raíz. Lo dice el script: busca en la
raíz y en un nivel de subcarpeta, y el nombre completo de la clase lo trae la
ruta — el que falló fue el `sed` que escribí a mano después, no el censo.)*

La segunda tiene además un detalle propio que no es del año y conviene no perder:
**no escribe `updated_by` ni `updated_at`** —está comentado en el código, a
propósito de quien lo escribió— así que la línea de auditoría es **todo** el rastro
que deja cambiar de qué falta deriva una situación.

Las dos tablas guardan **registro disciplinario de menores por año**. Es el mismo
argumento que hizo cerrar `ordinales`, un piso más abajo: allí se protege el
artículo del manual, aquí la anotación que lo cita.

---

## 3. Lo que este censo NO cubre, y hay que decirlo

1. **Las 50 restantes no están certificadas.** Mencionan `year_id` en algún sitio
   del método; que lo usen **para lo que hay que usarlo** —acotar la fila que se
   escribe, no elegir a cuál escribir— es otra lectura y no se ha hecho.
2. **Sólo mira `routes/api/*.php`.** Lo que escriba un comando de consola, un cron
   o un seeder no entra.
3. **La consecuencia no está medida en ninguna de las ocho.** Este censo dice
   *«aquí se puede escribir en el año de al lado»*, no *«aquí eso hace daño»*. Las
   dos preguntas se contestan en sitios distintos y la segunda cuesta más.
4. **Es de UN árbol y UN momento.** Se rehace con el script de abajo.

---

## 4. La orden que lo rehace

Vive en `tools/` sólo el día que alguien decida que esto se vigila; mientras sea
una medición de una tarde, va aquí entero para que se pueda repetir sin buscarlo:

```
python3 - <<'PY'   # contra la raíz del árbol que quieras medir
# 1. las 23 tablas con year_id salen de information_schema, no de una lista a mano:
#    SELECT TABLE_NAME FROM information_schema.COLUMNS
#     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'year_id'
# 2. por cada Route::(put|delete|patch|post) se extrae el cuerpo del método,
# 3. SE QUITAN LOS COMENTARIOS  <- esto es lo que separa 8 de 42,
# 4. y se exige el nombre de la tabla PEGADO al verbo que escribe.
PY
```

El guion completo quedó en el scratchpad de la sesión del 14 sep; lo que no se
puede perder es el **paso 3**, que es donde estaba el error.

---

## 5. De dónde sale cada cosa

- [16-escribir-en-un-anio-pasado.md](16-escribir-en-un-anio-pasado.md) — los cuatro
  catálogos, las diez escrituras cerradas y las dos decisiones del 14 sep.
- `App\Support\AnioCerrado` — qué significa «cerrado», y por qué es *anterior al
  actual* y no *distinto del actual*.
- `Autoriza::puedeEscribirEnUnAnioCerrado` — el criterio, con su medición de
  poblaciones.
