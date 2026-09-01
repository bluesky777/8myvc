# Lote G — los instrumentos

> Dos encargos, los dos salidos de errores medidos la noche del 31 ago 2026:
> **la cuarta ceguera de `unidades-sin-alcance.py`** y **el centinela de las
> columnas de `YearsController::postStore`**. Rama `fix/bi-lote-g`.

## 1. La cuarta ceguera: `IS NULL` sin alias no contaba como alcance

`alcance_de_unidades()` aceptaba `<=>` **con o sin alias** (usaba `ref`, con el
prefijo opcional) pero exigía `\b<alias>\.` para el `IS NULL`. O sea que una
consulta de **una sola tabla** —donde escribir el alias delante es innecesario y
nadie lo escribe— salía como «hay que acotarla» **estando acotada**, y justo con
la forma que la §1.6 del reparto bendice para cuando la consulta quiere las del
grupo a propósito. El arreglo es que la segunda rama use `ref` como la primera.

### La población, antes y después

Medido en mi árbol sobre `main` en `3329703` (`--csv`, `veredicto = hay que
acotarla` y `estado = no`):

| | Lecturas pendientes | Sitios |
|---|---|---|
| antes | **26** | 14 |
| después | **22** | 10 |

**Las cuatro que salen estaban acotadas y se contaban como trabajo pendiente.**
Ninguna entra: el cambio sólo puede aflojar, y aflojó exactamente donde se
diagnosticó. Leídas una a una, que es lo que manda la §1.5 — las cuatro son
`FROM unidades` **sin un solo `JOIN`**, con `alumno_id IS NULL` y con el porqué
escrito encima por quien las acotó:

| Sitio | Qué es |
|---|---|
| `PeriodosController.php:344` `putCopiar` | copia la estructura del periodo: sin alumno en el ámbito, la del grupo es la única con significado |
| `Unidad.php:275` `informacionAsignatura` | igual, y su docblock ya lo decía |
| `ChangeAskedController.php:1256` `asignaturas_dia` | «mis clases de hoy»: no hay alumno en el ámbito |
| `BoletinIndependienteController.php:278` `putPlanilla` | el reparto del grupo, que los tres `motivo` comparan |

> **La cifra que traía el encargo era otra, y conviene que quede dicho: «43 en
> 23».** No es que se moviera con mi arreglo — es que se midió antes de fundir
> los lotes B, C, A, D y E, que cerraron sus sitios. Sobre `main` de hoy y
> **antes** de tocar nada ya eran 26 en 14. Las dos cifras que importan para
> juzgar este cambio son las de la tabla, medidas con cinco minutos de
> diferencia sobre el mismo árbol.

### Y lo que faltaba de verdad: esta herramienta no tenía control

El encargo decía *«corre `AutopruebasDeLasHerramientasTest`, ese test existe
para esto»*. **Corría, y no comprobaba nada de esto**: el runner lleva cinco
herramientas y `unidades-sin-alcance.py` **no era una de ellas**. Tampoco tenía
`--control` ni `--autoprueba` que registrar.

O sea que el detector que **gobernó el reparto de una noche de cinco sesiones**
era el que no tenía a nadie mirándole el número, y por eso pudo equivocarse
cuatro veces:

1. no fundía las cadenas concatenadas → **no veía su propio arreglo**;
2. contaba el `FROM unidades` de los comentarios;
3. medía por línea y partía las consultas largas;
4. ésta.

**Las cuatro contando de MÁS, que es el error que no se delata solo:** su lista
gana sitios donde no hay nada, quien los revisa cierra cada uno «decidiendo no
tocarlo» —que aquí es una salida legítima (§1.5)— y el detector nunca queda mal.

Así que el arreglo entra con `--control` ejecutable y registrado en el runner.
**Ancla las seis formas, no un número del árbol**: un número obliga a
reescribirlo cada noche, y llega la noche en que se reescribe con el que sale en
vez de con el que debía salir. Comprobado **en rojo contra el código viejo**
(§1.4) — revertida sólo esa línea, falla el caso de la cuarta ceguera y ningún
otro.

### El aviso del `.git` del worktree

`AutopruebasDeLasHerramientasTest` sigue con **un** rojo, y no es de este lote ni
de nadie: `consultas-en-bucle.py --control` llama a `git show` y el `.git` de un
worktree apunta a una ruta del host que el contenedor no resuelve. Está contado
en la cabecera del propio test. Lo que importa aquí es que **el control nuevo
concluye**: 5 passed de 6.
