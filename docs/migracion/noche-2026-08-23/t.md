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
