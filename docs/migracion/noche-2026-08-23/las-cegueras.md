# Las cegueras de los detectores — barrido de la noche del 22 al 23 de agosto

> Montado por `8myvc-2f` a petición de quien coordina, juntando lo que trece
> lotes fueron encontrando por separado. **Ninguna de estas cegueras estaba
> escrita entera en un sitio**: cada lote apuntó la suya y siguió.
>
> Sale un patrón, y sale con población. Este repo lleva diez herramientas de
> medición y **esta noche mintieron ocho instrumentos distintos**. No es que las
> herramientas sean malas: es que **una herramienta que busca una señal encuentra
> la señal, no el hecho**, y la distancia entre las dos tiene formas fijas.

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

> El último no es un instrumento de medir sino de proteger, y por eso es el peor
> de la lista: **un test verde que fija un vaciado no avisa de nada y además
> impide arreglarlo**, porque el arreglo lo pone en rojo.

---

## Y la sexta, que no es de una herramienta: **la población**

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

---

## Y la que no tiene arreglo barato

Del [lote I](i.md), y es de otra clase:

> **Un control que comparte el sesgo del sujeto confirma el sesgo, no lo
> desmiente.** El barrido comprueba sus vacíos con una segunda pasada de
> superusuario — y ese superusuario **está en el mismo año que el sujeto**, así
> que ve el mismo vacío por la misma razón.

Lo único que se puede hacer con ésa es lo que hizo su lote: **medir cuánto mide**.
Siete rutas de las ~190 mudas de cada pasada, alrededor del 4%.

> **Una ceguera sin tamaño se lee como un descargo.** Con el tamaño escrito, se
> lee como lo que es: un límite conocido.
