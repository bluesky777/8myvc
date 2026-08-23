# Las cegueras de los detectores — barrido de la noche del 22 al 23 de agosto

> Montado por `8myvc-2f` a petición de quien coordina, juntando lo que trece
> lotes fueron encontrando por separado. **Ninguna de estas cegueras estaba
> escrita entera en un sitio**: cada lote apuntó la suya y siguió.
>
> Sale un patrón, y sale con población: **siete formas**, y una octava que no es
> de una herramienta sino de quien lee su salida.
>
> **El recuento, contado al cerrar y no al empezar** —la primera versión de este
> párrafo decía «ocho» y no se volvió a sumar cuando el documento creció, que es
> justo lo que este documento persigue—:
>
> | | Cuántos | Cuáles |
> |---|---|---|
> | **Detectores** que dieron una respuesta falsa | **8** | `identificadores-del-cuerpo.py`, el barrido de columnas pisadas, `escrituras-en-las-notas.py`, `interruptores-que-nadie-lee.py`, el barrido de rutas sin test, el detector de tests que juzgan, `respuestas-que-mienten.py`, el barrido por tipo de token |
> | **Aparatos de medida y de entorno** | **10** | el `vendor/` enlazado, `\| tail -60`, `ps \| grep`, el log que deja de crecer, el `pkill` que deja al hijo vivo, el `exit 143` resumido como «exit code 0», el snapshot que defendía el fallo, `git diff main <rama>`, el seed vacío, y un bucle de hashes escrito para comprobar esto mismo |
>
> **Dieciocho**, y varios mintieron de más de una forma. No es que las
> herramientas sean malas: es que **una herramienta que busca una señal encuentra
> la señal, no el hecho**, y la distancia entre las dos tiene formas fijas. Ésas
> son las que están aquí.

## Lo primero, porque es lo que decide qué hacer con esto

**Las cegueras empujan casi todas hacia el mismo lado: hacia «aquí no hay nada» y
hacia «esto ya está resuelto».** Ninguna hacia la duda. Eso las hace más caras que
un detector que se equivoca gritando: **un detector equivocado se corre y su
salida se lee; un renglón que dice «ya está» hace que nadie lo corra.**

Las dos excepciones —y las dos se cazaron— son las que dan **de más**: el barrido
de rutas del [lote G](g.md) que desde un worktree contaba 50 en vez de 49, y el
detector del [lote J](j.md) que daba 16 rutas sin juzgar cuando eran 10. Ésas son
peligrosas por lo contrario: **una ejecución rota que parece un hallazgo mejor que
una correcta no la cuestiona nadie.**

---

## Forma 1 — Ve un nombre que no es

La señal casa con algo que **se escribe igual y significa otra cosa**.

| Dónde | Qué casó | Qué era | Población | Lote |
|---|---|---|---|---|
| `identificadores-del-cuerpo.py` | la raíz `exig` | **`ColumnaSegura::exigir`**, que valida un **nombre de columna**, no de quién es la fila | **5 rutas** | [H](h.md) |
| El barrido de columnas pisadas | un método con `new X` **y** un `find()` | un **alta**: en un alta no hay nada que pisar | `ProfesoresController::postStore` (19 col.) y `AlumnosController::postStore` (**25**, la fila más grande de la lista) | [D](d.md), [E](e.md), [K](k.md) |
| El mismo barrido | la asignación | la línea de antes: cada una iba dentro de su `if (Request::has(...))` | `NotaComportamientoController::putUpdate` | [C](c.md) |

**La ironía de la forma 1**: la raíz `exig` es corta **a propósito**, porque la
[§53](../05-codigo-muerto-y-roto.md) descubrió que el detector se quedaba **ciego
ante un nombre nuevo** —`exigeQue…` frente a `exigirQue…`—. O sea que ésta es la
misma decisión mirada desde el otro lado:

> **Ensanchar una señal para no perder nada la hace tragar de más.** Perder por
> estrecha y tragar por ancha son el mismo error, y las dos veces se paga en el
> mismo sitio: **una ruta que sale del lado limpio no la vuelve a mirar nadie.**

---

## Forma 2 — La comprobación existe, y está fuera de donde el detector mira

La más frecuente de la noche, y la que más falsos «sin comprobar» produce.

| Dónde está la comprobación | Qué la escondía | Población | Lote |
|---|---|---|---|
| **Dentro del guard** (`ExigirPersonaPropia` mira los ids del cuerpo por su nombre) | el detector solo leía el cuerpo del método | **9 rutas** | [H](h.md) |
| **Dentro del método** (autenticación con usuario y contraseña en el cuerpo) | la columna `guard` decía `—`, que se lee como «sin guard» | **3 rutas** (`tardanzas/subir/*`) | [H](h.md), [L](l.md) |
| **Dentro de un helper privado** con un `SELECT` y un `DB::update` crudo | el patrón buscado era `$obj->col = Request::input(...)` | `EscalasDeValoracionController::putUpdate` — no salía **ni como candidato** | [A](a.md) |
| **Dentro de un `foreach` que no se ejecuta** | el código **sí desreferencia**; el bucle recorre las asignaturas del profesor, y un profesor que no existe no tiene | 3 informes | [M](m.md) |

La última es la que menos se parece a una ceguera de herramienta y es la misma
cosa **un piso más arriba**: la ceguera de leer código.

> **El `foreach` parece la protección y es la puerta.** Leyendo se concluye
> «desreferencia, luego revienta», que es una inferencia que casi siempre acierta.
> Aquí no: **la línea que habría reventado es inalcanzable justo cuando el id es
> malo.**

---

## Forma 3 — Cuenta la coincidencia y no el contexto

El `grep` acierta y la conclusión no, porque **lo que separa un caso del otro no
está en la línea**.

| Qué se contó | Qué era | Lote |
|---|---|---|
| 8 `Debugging::pin` vivos | **5 basura y 3 mecanismo**: los del importador son el único rastro de las importaciones viejas y el posible punto de control de dos métodos que no usan el mecanismo nuevo | [L](l.md) |
| 3 sitios con el formato `'Y-m-d G:H:i'`, **uno leyendo** | **2 escrituras y ningún lector**: el tercero lleva años dentro de un `/* */` | [K](k.md) → corregido en [L](l.md) |
| «alguien comprueba propiedad» | la palabra **`exigen`** dentro del comentario «se exigen abiertos todos los periodos» | [H](h.md) |
| «este método frena la escritura» (`escrituras-en-las-notas.py`) | leía **prosa de los docblocks** | noches anteriores |

> **Ocho coincidencias de un grep no son ocho fallos.** Lo que las separa no está
> en la llamada sino **en el método donde vive**.
>
> Y la regla operativa, que es barata: **un detector que busca una palabra tiene
> que mirar solo el código** — y **un `grep` a mano se corre con los cinco
> renglones de alrededor delante**. Que la lección estuviera escrita no impidió
> repetirla una hora después, en la misma sesión.

---

## Forma 4 — Encoge en silencio

Lo que **no** se midió, sin decirlo.

| Qué encogió | Cuánto | Lote |
|---|---|---|
| El script de interruptores: ficheros de cliente **no leídos por pasar de 1 MB** | el `0` de «no lo usa nadie» era un `0` de «no lo miré» | [G](g.md) |
| El mismo, con **rutas relativas desde un worktree**: contaba 50 donde había 49, y **solo avisaba por `stderr`** | +1, y hacia arriba | [G](g.md) |
| `| tail -60` sobre una suite: devolvió **el código de salida del `tail`** | un `0` que no significaba nada | [A](a.md) |
| El barrido de rutas sin test buscaba `profesores/show/{id}` **con las llaves dentro** | 26 rutas «sin comprobar» que sí lo estaban | [E](e.md) |
| El detector de tests que juzgan cortaba por `« con data set »` — **PHPUnit lo escribe en inglés** | 16 rutas sin juzgar donde había **10** | [J](j.md) |
| El seed vacío: `bitacoras`, `ws_actividades`, `piars_*`, `change_asked`, `debugging` **sin una fila** | un test que busca la condición pasa **sin medir nada** | [B](b.md), [F](f.md), [K](k.md), [L](l.md) |
| `columnas-en-los-modelos.php` avisa **en cada ejecución** de que `App\Models\Disciplina` no tiene tabla | es cierto y es benigno —no es un modelo Eloquent, es el contenedor de las tres consultas de convivencia—, pero **un aviso que sale siempre se deja de leer**: el día que falte de verdad la tabla de un modelo, la línea será indistinguible | [S](s.md) |

El arreglo que el lote G le puso al suyo es el que vale para todos, y es el mismo
que la columna «quién comprueba» del lote H:

> **Un detector que dice POR QUÉ dice lo que dice no puede mentir en silencio.**
> Imprimir «no leídos: N» o «la señal la disparó `Autoriza::exigir`» convierte una
> afirmación en evidencia — y las dos veces **cazó un falso positivo en su primera
> ejecución**.

---

## Forma 5 — El instrumento contesta con la cara del problema

La más cara, porque **el resultado falso es indistinguible de un hallazgo**.

| El instrumento | Qué parecía | Qué era | Cómo se cazó | Lote |
|---|---|---|---|---|
| `vendor/` enlazado con symlink en un worktree | los tests pasan | el árbol cargaba **el `app/` de otro** | `stan` daba 2 errores en el worktree y `[OK]` en el principal, con el mismo `phpstan.neon` | [15](../15-la-noche-en-paralelo.md) |
| Rojos en familias no tocadas, uno de 53 s | «mis cambios rompieron algo» | **deadlock en `personal_access_tokens`**: dos tandas contra la misma base, y las dos eran suyas | correr esas clases solas y leer el `SQLSTATE` | [C](c.md) |
| Lo mismo, pero con una tanda ajena | ídem | un `pkill -f "artisan test"` mató **el envoltorio y dejó al hijo vivo**, reparentado a init | `pgrep -f "phpunit.*worktrees/X"` — **el hijo se llama `phpunit`, no `artisan`** | [A](a.md), [B](b.md) |
| `ps | grep worktrees/e` | «no corre ninguna suite mía» | la línea del proceso es `php artisan test` a secas: el árbol y la base viven en `/proc/<pid>/environ` | leer el `environ` de cada pid | [E](e.md) |
| El log que deja de crecer | «la suite murió» | **búfer de bloque**: parado justo en 10.210 bytes | el tamaño exacto del fichero | [E](e.md) |
| Un `docker exec` muerto por `pkill` | el harness lo resumió como **«completed, exit code 0»** | **exit 143** | escribir `EXIT=$?` dentro del contenedor, junto al log | [B](b.md) |
| Un snapshot de contrato | «esto está cubierto» | **el snapshot guardaba el fallo como si fuera correcto** | leer lo que afirma, no que exista | [E](e.md) |
| **El mismo snapshot, al día siguiente** | «una respuesta cambió de forma» | `grupos-show.json` es el único de los seis que se movió y ya existía, y lo que cambió es **el fixture**: `GruposController::getShow` no se tocó en toda la noche | mirar si se movió la ruta o el test | [S](s.md) |
| **`DB_TEST_DATABASE` en `tools/salud-de-las-definitivas.php`** | «esto mide mi base de tests, como todo lo demás de la noche» | esa herramienta lee la conexión por defecto —`DB_DATABASE`—, **a propósito y documentado en su cabecera**: es un informe de salud de un colegio de verdad. Con `DB_TEST_DATABASE` puesto, su primera línea dice `base simonbolivar` | leer la primera línea de su salida, que **nombra la base** | [S](s.md) |
| **`git diff main <rama>`** —con dos puntos— para ver qué aporta una rama | «esta rama **borra 8.536 líneas**» | la rama sale de un `main` viejo: ese diff incluye **deshacer los lotes fundidos después**. Lo que aporta son **895 insertions(+), 1 deletion(-)** | `git diff main...<rama>` **o** `git diff $(git merge-base main <rama>) <rama>` — los dos dan lo mismo | [L](l.md) |

> El **snapshot** no es un instrumento de medir sino de proteger, y por eso es el
> peor de la lista: **un test verde que fija un vaciado no avisa de nada y además
> impide arreglarlo**, porque el arreglo lo pone en rojo.
>
> Y `grupos-show.json` tiene algo que no tiene ningún otro de esta tabla: **mintió
> en las dos direcciones con horas de diferencia.** Primero **escondiendo** un
> fallo —verde sobre un grupo al que le habían borrado el titular— y después,
> arreglado el test, **fingiendo un cambio de contrato que no existe** para quien
> audite la tanda mirando qué snapshots se movieron. **Un snapshot cambiado no es
> una respuesta cambiada**, y el mismo fichero enseña las dos lecturas
> equivocadas.
>
> Y el **último** es el que más cerca está de hacer daño esta noche, porque
> aparece justo en el momento de fundir, **que es cuando menos margen hay**:
> medido en las tres ramas pendientes, L «borra» 8.536 líneas y H «borra» 14.068,
> **y las dos aportan menos de 900**. `git merge-tree` dice que ninguna de las
> tres tiene conflictos; el `diff` contra `main` es el que asusta, y asusta por
> **comparar contra un punto que la rama nunca tuvo**.
>
> Las tres formas, medidas en L:
>
> ```
> git diff --shortstat main fix/lote-l-sobras       57 ficheros, 1014 +, 8536 −   <- la trampa
> git diff --shortstat main...fix/lote-l-sobras      6 ficheros,  895 +,    1 −
> git diff --shortstat $(git merge-base …) …         6 ficheros,  895 +,    1 −
> ```
>
> **Los tres puntos dan exactamente lo mismo que la `merge-base`** y son más
> cortos de escribir; la `merge-base` es más explícita de leer. Lo que hay que
> marcar como trampa es **el de dos puntos**, que es el que uno teclea sin pensar.

### Una de la forma 5 fabricada mientras se escribía esto

Ya cerrado el barrido, auditando si alguien había tocado mis tests después de
fundirlos, un bucle de shell comparó los hashes de blob de doce ficheros y dijo
que **los doce habían cambiado**. Doce de doce es un número que no se parece a
«otro lote tocó uno»: se parece a que algo grande pasó.

No pasó nada. **Los doce estaban idénticos**, y `git diff` sobre uno de ellos
salía vacío en un segundo. Lo que fallaba era el bucle —la captura de las
variables dentro de la sustitución—, y su salida tenía **exactamente la forma de
un hallazgo**.

> **El instrumento era el bucle escrito para comprobar, no lo comprobado.** Lo
> único que lo cazó fue la regla que este mismo documento acababa de escribir:
> **mirar el diff de verdad antes de creerse el resumen** — y que doce de doce es
> demasiado redondo.

Se cuenta porque es la prueba más barata de que estas formas no se evitan
sabiéndoselas: **el barrido estaba escrito y firmado cuando pasó.** Lo que las
evita es el hábito de pedirle al instrumento que enseñe el caso concreto.

### Y una que no es una ceguera del instrumento sino de quien lo lee — dos veces en la misma hora

Dos sesiones distintas, con veinte minutos de diferencia, tomaron **el límite
declarado de un instrumento por un defecto**:

| Instrumento | Lo que se creyó | Lo que dice de sí mismo |
|---|---|---|
| `tools/salud-de-las-definitivas.php` | «no respeta `DB_TEST_DATABASE`» | su **cabecera, línea 24**, documenta `-e DB_DATABASE=otrocolegio`: mira la base real **a propósito**, porque es un informe de salud sobre datos de verdad |
| `AutorizacionTest` | «la red tiene un hueco» | **declara su alcance en su propio comentario** |

> **Un instrumento que declara su alcance no tiene un hueco: tiene un límite.** Y
> el sitio donde lo declara —su cabecera, su docblock— es **justo el que no se lee
> cuando uno va a usarlo deprisa**.

Por separado, cada una parece mala suerte. Juntas y en la misma hora, dicen que
**el hábito que falta no es medir mejor: es leer la cabecera antes de correr**.

Y lo que sí queda del primer caso, con la mitad que era cierta: **`DB_TEST_DATABASE`
es una convención de `phpunit.xml`, no del proyecto.** Quien traiga puesta la
costumbre de la noche —«a mis herramientas les pongo mi base»— correrá esa
herramienta creyendo que mide su base de tests **y estará midiendo el colegio**.
Se caza leyendo su primera línea, que nombra la base.

---

## Forma 6 — La señal que se busca no es la forma que tiene el fallo

Distinta de las cinco anteriores: aquí el detector **funciona bien** y su
definición **no alcanza** al caso. No hay nada que arreglarle; hay que saber qué
no cubre.

| El detector | Qué busca | El caso que no cabe | Lote |
|---|---|---|---|
| `respuestas-que-mienten.py` | métodos que **frenan** la escritura y contestan 200 igual | `alumnos/update` sin `username`: **no hay nada que frene**, el `save()` sencillamente no está en ese camino. Contesta 200 con los cambios en el JSON y no escribe | [K](k.md) |
| El mismo | ídem | Cuatro rutas de ordinales que contestan «Cambiado» / «Eliminado» sin tocar una fila: **el `WHERE` no casa**. La escritura **corre y no encuentra a quién** | [B](b.md) |
| `identificadores-del-cuerpo.py`, columna `escribe` | que el método contenga `UPDATE`/`DELETE`/`->save()` | **ve la sentencia, no las filas**: `ESCRIBE` no quiere decir «cambió algo» | [E](e.md), [H](h.md) |

> **La señal que se busca no es la forma que tiene el fallo.** Y el aviso
> práctico: cuando una serie se dé por agotada porque su herramienta «solo da un
> sitio», lo que está agotado es **la forma que la herramienta sabe ver**.
>
> `respuestas-que-mienten.py` empezó dando catorce sitios y ahora da uno. Es un
> resultado — **para su definición**. Esta noche aparecieron **cinco respuestas
> que mienten** por caminos que esa definición no nombra.

---

## Forma 7 — El control comparte el sesgo del sujeto

La única sin arreglo barato, y por eso va aparte. Del [lote I](i.md).

El barrido por tipo de token comprueba sus vacíos con **una segunda pasada de
superusuario**: si el superusuario ve lo mismo que el sujeto, el vacío no era del
guard. Pero ese superusuario **está en el mismo año que el sujeto** —los cuatro de
esta noche salieron con `year_id = 8`—, así que **ve el mismo vacío por la misma
razón**.

> **Un control que comparte el sesgo del sujeto confirma el sesgo, no lo
> desmiente.**

Lo único que se puede hacer es lo que hizo su lote: **medir cuánto mide**. De las
28 rutas con `{grupo_id}` en la URL, 19 no miran el año para nada, 9 lo nombran y
**7 quedan mudas sin explicación** — alrededor del **4%** de las ~190 mudas de
cada pasada. Y al medir esas siete, **seis no estaban cerradas**.

> **Una ceguera sin tamaño se lee como un descargo.** Con el tamaño escrito, se
> lee como lo que es: un límite conocido.

---

## Y la última, que no es de una herramienta: **la población**

No es una ceguera de detector. Es la de quien lee su salida, y **apareció cinco
veces esta noche**:

| Serie | Se cerró sobre | Lo que faltaba | Lote |
|---|---|---|---|
| §78, «los catálogos que escriben con el cuerpo vacío» | **nueve rutas** | `certificados/store` no era una de ellas | [F](f.md) |
| §74, «`para_alumnos` no decide nada» | **un interruptor** | eran **tres** | [F](f.md) |
| §68, «los campos que se pisan en `alumnos/update`» | el lado de **`users`** | las **23 columnas de `alumnos`**, con la herramienta ya llamada dos líneas antes | [K](k.md) |
| §88, «el borrado de una bitácora» | **una** de siete lecturas de la tabla | seis, y **una de ellas NO debe filtrar** | [B](b.md) |
| §89, la operación de mandar un alumno a la papelera | **dos** de cuatro sitios | los otros dos, en controladores de boletines | [C](c.md) |

> **Cerrar una serie no es cerrar la operación.** Y lo que hay que escribir al
> cerrar no es «arreglado»: es **sobre qué población se cerró**. Las cinco veces,
> el documento decía la verdad de lo que había mirado y nadie podía saber qué se
> había quedado fuera.

---

## Lo que se puede hacer con esto, y cuesta poco

1. **Cuando una cabecera, una tabla o un renglón diga que algo ya está resuelto,
   comprobar el número contra la herramienta antes de creerlo.** Las cuatro veces
   del lote G bastó con correr lo que ya existía.
2. **Que el detector diga por qué.** Una columna con la señal que disparó, o una
   línea de «no leídos: N». Las dos veces que se hizo, cazó un falso positivo en la
   primera ejecución.
3. **Contar cuántas entradas la propia herramienta no supo procesar**, y
   publicarlo junto al resultado. Es lo que ninguna de las dos versiones del
   número del lote J decía por sí sola.
4. **Al cerrar una serie, escribir la población.** No «arreglado»: «arreglado en N
   de M, y los M − N son éstos».
5. **Un `grep` a mano se corre con contexto**, y su salida no es una conclusión.
6. **Antes de diagnosticar un rojo, comprobar que la tanda estaba sola**:
   `pgrep -f "phpunit.*worktrees/X"` —el hijo se llama `phpunit`— y el código de
   salida escrito **dentro** del contenedor con `EXIT=$?`.
7. **Cuando una serie se dé por agotada porque su herramienta «solo da un sitio»,
   escribir de qué definición está agotada.** No es lo mismo «no quedan» que «no
   quedan de la forma que sé ver».

---
