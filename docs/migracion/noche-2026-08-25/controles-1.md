# CONTROLES-1 — los controles que nadie ejecuta

**Sesión `8myvc-12`** · rama `fix/controles-que-nadie-corre` · no toca `app/` ni `routes/`.

---

## §1. El inventario, con las tres cuentas

    herramientas en tools/ ................................ 35
    con autoprueba EJECUTABLE (--control / --autoprueba) ... 4
    con control positivo EN PROSA y nada que lo ejecute .... 1
    sin declarar ningún control ........................... 30

**La ficha decía «6 con bandera de autoprueba» y son 4.** Las otras dos que aparecen si
se busca por vocabulario —`interruptores-que-nadie-lee.py`, `tablas-calientes.php`—
**hablan de comprobarse a sí mismas pero no declaran un control positivo ni traen
bandera**. *Mi primer recuento dio 1 en prosa y 4 ejecutables con una regex estrecha; el
bueno salió listando a mano las banderas que cada herramienta lee de su `argv`.*

### Y el hallazgo no está en esas cuentas

**De las 4 ejecutables, ninguna la corría nada.** `DefinicionDeLosDetectoresTest` vigila
las **definiciones** que los documentos citan —qué tablas mira cada detector— y eso es
otra cosa.

> **Las dos piezas existían y no estaban conectadas.** *Un control positivo que nadie
> ejecuta es una intención; uno ejecutable que nadie invoca es lo mismo con mejor cara.*

`tests/Unit/AutopruebasDeLasHerramientasTest` es el cable: corre las cinco y distingue
**tres resultados, no dos** — pasa, falla, y **no concluyente**.

---

## §2. El «no concluyente» es un hallazgo, y es mío

`consultas-en-bucle.py --control` compara el mismo fichero **antes y después de un
commit**, y para eso llama a `git show`. **Dentro del contenedor eso no funciona en un
worktree**: el `.git` de un árbol de trabajo es un fichero que apunta a una ruta **del
host**, que no existe en `/app`. `git` contesta *«not a git repository»*.

**O sea que escribí ese control y lo verifiqué en el host, y la suite corre dentro.** El
instrumento correcto sobre el objeto equivocado, esta vez dentro del control mismo.

**Lo que lo salva es que devuelve 2 y lo dice**, en vez de un `OK` indistinguible de uno
real. Por eso el runner tiene tres salidas y no dos: el 2 no es aprobado **ni** fallo, y
la lista de los no concluyentes **se fija**, así que el día que una que hoy concluye deje
de hacerlo, el caso cae.

---

## §3. La tercera forma de morir de un control, que la ficha no listaba

La ficha preveía dos: **el detector está roto**, o **el control cita algo que ya no
existe**. Al hacer ejecutable el de `verdad-laxa-que-escribe.py` salió **rojo**, y no era
ninguna de las dos.

Su control decía *«tiene que encontrar el `if` del contador de certificados dentro de
`detailedNotasGrupo()`»*. Ya no lo encuentra — **porque ese `if` se arregló**: el commit
`0473a9b` lo pasó a `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, que es justo la cura de la
[05 §231](../05-codigo-muerto-y-roto.md). El detector hizo **exactamente lo que debía**: no
marcar lo que ya no es un fallo.

> **Un control positivo anclado en un fallo VIVO muere cuando alguien arregla el fallo, y
> entonces parece que el detector se rompió.** El siguiente en pasar «arregla» el
> detector. Es la más cara de las tres formas, porque **castiga justo a quien arregla el
> código.**

**La cura: anclarlo en un caso sintético**, dentro de la propia herramienta. Ahora
comprueba tres cosas sobre un PHP de mentira —que reconoce la forma laxa que escribe, que
**no** marca la estricta, y que **no** marca una laxa que no escribe— y **no depende de
que el repositorio siga teniendo el fallo**.

*Lo que un control tiene que comprobar es que el detector reconoce la forma, no que el
código siga enfermo.*

---

## §4. El control del control

Cada pieza nueva se rompió a propósito y se vio caer **por su motivo**, no por otro:

| se rompió | cayó diciendo |
|---|---|
| el patrón `CITA` de `secciones-citadas.py` | *«La autoprueba de `secciones-citadas.py` FALLA»* — el runner lo cazó |
| el reconocimiento de la forma laxa | *«NO reconoce la forma laxa que escribe — su lista se queda corta»* |
| la exigencia de que escriba | *«marca una laxa que NO escribe — le falta la tercera condición»* |

**Y una trampa de medición por el camino:** los primeros códigos de salida los leí **a
través de un `pipe`**, así que `$?` era el de `tail` y las cinco salían `exit=0` —
incluida la que devuelve 2. *El instrumento otra vez, y en la línea más corta del lote.*

---

## §5. Lo que NO se hizo, y por qué

- **No se cambió lo que mide ningún detector.** Sólo su control. Tocar la definición
  movería las cifras de todos los informes anteriores sin que sus autores se enteren.
- **No se «arregló» ningún detector porque su control fallara.** El único que falló lo
  hizo por la tercera forma de la §3, y lo que se arregló fue **el control**.
- **A las 30 sin control no se les inventa uno.** Un control positivo necesita una
  respuesta conocida, y fabricarla sin tenerla es escribir prosa nueva.

---

## §5.bis. El criterio de cierre que puede salir verde sin ejecutar el trabajo

**Este lote estuvo a un mensaje de cerrarse con un número que no lo cubría.**

`--testsuite=Contrato` dio **1.369 verdes** y lo escribí como verde del lote. Es
`./tests/Contrato` en `phpunit.xml`, **y el entregable de CONTROLES-1 vive en
`tests/Unit/`**: esa suite **no ejecuta ni una línea de lo que este lote hizo**.

**La única señal era que el 1.369 es exactamente el mismo número que dio DEF-108**, y ésa
es una señal que sólo ve quien recuerda la cifra anterior. *Un número de cierre que no
incluye el trabajo que cierra es un `[OK]` de los de la [§220](../05-codigo-muerto-y-roto.md).*

> **`Contrato` para no haber roto nada, MÁS la suite donde viva lo tuyo.** Y **si el
> entregable no lo ejecuta ninguna suite, ése es el primer hallazgo del lote** — no un
> detalle de formato.

*No es un defecto de esta ficha: casi todas las de la noche decían «Contrato verde», y
varias eran de lotes cuyo entregable vive en `tools/` o en `tests/Unit/`.* Corregido en el
briefing y en las fichas abiertas.

---

## §5.ter. El carril: la puerta está en cada corrida, no al empezar el lote

**Corrí los tests dirigidos, `pint`, `larastan` y una suite de nueve minutos sin tener
carril**, con los dos ocupados por otras dos sesiones —una de ellas reconstruyendo su
base—. Lo descubrí al ir a soltarlo: *«no tenías ninguno de los dos carriles»*.

**Por qué se fue, que es lo único útil:** el turno se cogía siempre *antes de una tanda*, y
**este lote empieza sin docker** —el inventario es lectura de ficheros—. Para cuando llegó
el momento de correr, ya estaba «dentro» del lote y no volví a la puerta.

> **La regla «el turno es de una corrida y no de un lote» no cubre el lote que empieza sin
> contenedor.** La forma que sí funciona: **la puerta está en cada corrida.**

Y lo que lo hace difícil de detectar desde fuera: **el `ver` del script no enseña a quien
corre sin coger**, así que la comprobación que él mismo ofrece —*«mira quién tiene el otro
carril»*— **no sirve contra esto**. Quien tenía los carriles no tenía forma de enterarse.

---

## §6. Cierre

    5 autopruebas ejecutables (eran 4) · 5 cableadas al suite (eran 0)
    1 marcada NO CONCLUYENTE, con su motivo escrito
    controles nuevos rotos a propósito y vistos caer por su razón: 3

**Y una consecuencia que vale más que el número:** ahora que se ejecutan, **un control que
muera avisa**. El de `verdad-laxa-que-escribe.py` llevaba muerto desde las 12:50 y lo
supimos al conectarlo, no al usar su lista.
