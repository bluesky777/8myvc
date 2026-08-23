# Lote T — Lo que destapó la curva · §146–§149

> Sesión `8myvc-4f`. Rama `fix/lote-t-lo-que-destapo-la-curva`, árbol
> `.worktrees/t`.
>
> Este lote no salió de leer código ni de una herramienta nueva: salió de
> **auditar un detector propio**. La curva de profundidad del lote P demostró que
> el barrido de «GET que escriben» miraba **a un salto**, y a dos saltos había
> más. Esto es lo que había.

## §146 — Mirar la ficha de un alumno le crea los requisitos que le faltan

```
AlumnosController::putShow
  -> comprobar_alumno_con_grupos
     -> traer_requisitos_detalle    INSERT INTO requisitos_alumno(... "falta" ...)
```

Es la forma del §133 —una pantalla que se fabrica al abrirla— pero en un endpoint
**llamado `show`**, que es la palabra con la que este repo nombra las lecturas. El
verbo es `PUT` porque aquí `PUT` se usa para leer con cuerpo, así que **ni el
nombre ni el verbo avisan**.

Y lo alcanza **el propio alumno**: `putShow` no lleva guard de ruta, se defiende
por dentro dejando que un alumno pida lo suyo — y ese camino pasa por el mismo
sitio.

> La defensa de dentro **funciona**, y aun así deja pasar una escritura que nadie
> sabía que existía. La tabla de rutas no dice si algo está defendido; tampoco
> dice si escribe.

### Hoy no escribe nada, y por eso se fijan las dos cosas

**En la base entera hay CERO filas de `requisitos_matricula`**, en los ocho años.
El bucle que inserta no da ni una vuelta: la escritura está ahí, alcanzable y sin
candado, **dormida porque la tabla que la alimenta está vacía**.

Es la cuarta mina de esta serie y **la de mecha más corta**: las otras esperan a
una pantalla nueva, a una carpeta o a una línea de `SELECT`; ésta espera a que un
colegio **use una función que ya tiene** — definir los requisitos de matrícula.

Por eso el test fija **las dos**: que hoy no escribe, y qué escribe en cuanto haya
un requisito definido —creado dentro de la transacción, porque en la base no hay
ninguno—. **Un test que solo mirara hoy diría «no escribe» y estaría en verde el
día que empiece.**

Medido además: **repetir no duplica**, y la ficha de otro alumno sigue cerrada.

## §147 — Los dos GET que crean PIAR, y el que se separó de su gemelo

El PIAR es el Plan Individual de Ajustes Razonables: el documento de un alumno con
necesidades educativas especiales. Que una fila suya nazca sola importa más que en
otras tablas.

Los dos estaban invisibles para un barrido en línea —la escritura vive en
`Piars/Utils`— y **los dos llevan el nombre diciéndolo**: `getCreatePiarAsignatura`
y `getAlumnosPiar`. No es que el código se escondiera: **es que el detector no
llegaba.**

```
PiarsAlumnoUtils::getAlumnosPiar                if ($alumnoGrupo->nee) { INSERT }
PiarsAsignaturasUtils::getCreatePiarAsignatura  INSERT sin mirar nada
```

La primera solo le abre el PIAR a quien ya está marcado con `nee = 1`: eso es
mecanismo, el contenedor del documento de quien lo necesita. **La segunda lo crea
para el `alumno_id` que llegue por la URL**, tenga `nee` o no. Es la divergencia
entre copias otra vez —como `find`/`findOrFail` del §104— pero aquí lo que
diverge es **a quién se le abre un expediente**.

**No se cierra**: añadirle el `if ($alumno->nee)` apagaría la pantalla de PIAR por
asignatura para cualquier alumno que el colegio esté valorando **y todavía no haya
marcado**, y eso es una decisión del colegio sobre su propio procedimiento.

### Y para una familia revienta, con el guard haciendo su trabajo

`getAsignaturas` rellena `$asignaturas` en dos ramas —`Profesor` y `Usuario`— y
**no tiene `else`**. La ruta lleva `persona.propia`, que deja pasar a un alumno
sobre lo suyo y a un acudiente sobre sus acudidos: esas dos ramas llegan al
`count($asignaturas)` con la variable sin definir. **Error fatal en PHP 8.**

O sea que el guard comprueba correctamente que el alumno es suyo, y **lo que hay
al otro lado no sabe atenderlo**. Un `persona.propia` en la ruta la hace parecer
pensada para familias, y no lo está.

### Un detalle de método que casi me da un verde hueco

Las dos ramas **no alcanzan lo mismo**: la de `Profesor` recorre **sus**
asignaturas, la de `Usuario` recorre **todas las del grupo**. Con token de
profesor del seed se crean **cero** filas, porque no da clase en ese grupo.

**Un test escrito con el token de profesor habría dado verde diciendo que no
escribe.** El token con el que se mide es parte de la medición.

## §148 — Las once del escalón 2, clasificadas

Eran trece. **Dos no sobreviven a leerlas**, y eso es una corrección a un número
mío que ya estaba fundido — ver §149.

De las once que quedan:

| Ruta | Veredicto |
|---|---|
| `GET definitivas_periodos` | ya medida: **§137**, documentada y sin ejecutar |
| `GET piars-asignaturas/asignaturas/{...}` | **§147** |
| `GET piars-grupos/contexto-de-grupo/{grupo_id}` | **§147**, y es la que comprueba `nee` |
| `PUT unidades/de-asignatura-periodo/{...}` | ya decidida por Joseth: **§47.2** |
| `PUT ChangesAsked/solicitar-cambios` | mecanismo — «solicitar» escribe la solicitud |
| `POST importar/algo/{year}` | mecanismo — punto de control de una importación |
| `POST matriculas/matricular-en` | mecanismo, y **se defiende por dentro** |
| `POST matriculas/matricularuno` | idem |
| `PUT acudientes/guardar-valor` | mecanismo — «guardar» escribe |
| `PUT alumnos/guardar-valor` | idem |
| `PUT alumnos/guardar-valor-varios` | idem |

**Siete son mecanismo, y eso también hay que comprobarlo**, no darlo por hecho
porque el nombre lo diga. Los dos sitios donde podía haber algo:

- **Los tres `guardar-valor` son setters genéricos** —reciben el nombre de la
  columna por el cuerpo— y eso es exactamente la forma de una escritura
  arbitraria. **No lo es**: la rama por defecto pasa por
  `ColumnaSegura::exigir('alumnos', $propiedad)`, que ya lo cerró otra pasada.
  Resultado, no hueco.
- **Las dos de matricular** no llevan guard de ruta y **se defienden por dentro**:
  `$user->tipo == 'Profesor' && $user->profes_can_edit_alumnos`, o superusuario.

### Y una cosa que dije y retiro: `AutorizacionTest` no tiene ahí ningún hueco

Al ver que `matriculas/matricularuno`, `matricular-en` y `acudientes/guardar-valor`
—abiertas y defendidas por dentro— **no están en las exenciones**, lo mandé como
hallazgo: «una lista que se cree completa». **Era falso**, y lo di por hueco sin
leer el instrumento.

La red **se salta esas familias a propósito**, con el umbral escrito en su propio
comentario: hacen falta **≥2 hermanas con guard** y que las que no lo llevan sean
minoría clara (`sin <= max(2, total/4)`). Medido:

| familia | con guard | sin | ¿la mira? |
|---|---|---|---|
| `acudientes` | 1 | 13 | **no** (umbral: sin ≤ 3) |
| `matriculas` | 1 | 15 | **no** (umbral: sin ≤ 4) |
| `alumnos` | 1 | 16 | **no** (umbral: sin ≤ 4) |
| `perfiles` | 5 | 17 | **no** (umbral: sin ≤ 5) |

Y su comentario dice por qué: *«una familia mayoritariamente sin guard es otra
pregunta y más grande… aquí daría sesenta líneas de ruido y taparía las cinco que
importan»*.

**Y hay una segunda mitad que tampoco miré**:
`test_cuantas_de_cada_familia_llevan_guard` guarda **un snapshot con el recuento
de cada familia**, justo para que una familia que se abre o se cierra aparezca en
el diff — y su docblock **nombra `matriculas/*` como ejemplo** de lo que el
`assert` no puede afirmar. Lo que presenté como descubrimiento **ya estaba escrito
en el fichero que estaba criticando**.

> Un instrumento que declara su alcance **no tiene un hueco: tiene un límite**.
> Confundir las dos cosas manda a alguien a arreglar lo que ya está decidido.
>
> Y el error es el mío de toda la noche girado del revés: llevo horas repitiendo
> que *medir una ruta no es haberla juzgado*, y aquí **juzgué un instrumento sin
> leer su docblock**, que es lo mismo que mirar una ruta y no leer el método.

## §149 — Erratas de mi propia curva: eran 313, no 315

La curva del lote P publicó **315 rutas que escriben**. Al ir a repartir el
escalón 2 leí los ficheros destino uno a uno y **`BolfinalesController.php` no
tiene ni una escritura**: cero `DB::update`, cero `DB::insert`, cero `->save()` en
el fichero entero. Comprobado hasta el final: `detailedNotasGrupo` llama a
`Grupo::alumnos`, `Grupo::datos`, `Nota::puestoAlumno` y `Year::datos` —**los
cuatro solo leen**— y a dos métodos suyos, en un fichero sin escrituras.

**`bolfinales/detailed-notas-year` y `-year-group` no escriben.** El total correcto
es **313**:

| | |
|---|---|
| escalón 1 | **300** — recontado con una implementación **independiente**, sin recursión |
| escalón 2 | **11** — 8 de 9 ficheros destino verificados a mano |
| escalón 3 | **2** |
| **total** | **313** |

**La forma de la curva no cambia**: 300 / +11 / +2 / **0 de 4 a 8**. Sigue
convergiendo en 3, que era la afirmación que importaba.

### Lo que enseña, que es lo que no quiero que se pierda

Antes de publicar el 315 **sembré el detector** con nueve casos ya leídos —seis
que escriben, tres que no— y pasó **9/9**, con las profundidades correctas. Y aun
así el número traía dos inventados.

> **Sembrar el detector con casos conocidos prueba que ALCANZA. Leer una muestra
> de sus positivos prueba que ACIERTA.** Un instrumento puede pasar la siembra
> 9/9 y seguir inventándose resultados: son dos comprobaciones distintas y hacen
> falta las dos.

La segunda la hice por accidente, al ir a repartir el trabajo. Debería ser un
paso, no una casualidad.
