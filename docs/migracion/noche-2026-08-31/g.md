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

## 2. El otro contador del mismo fichero: las «desnudas» no miraban el ámbito

`desnudas()` imprime *«N consultas comparan `alumno_id` SIN ALIAS uniendo
`unidades`: son un 500 (1052 ambiguous)»*, y el 1 sep 2026 señalaba **dos**:

- `DefinitivasDeAsignatura:910` `porcentajeDeLasUnidades`
- `BoletinIndependienteController:455` `motivoDelVacio`

**Las dos son `SELECT … FROM unidades WHERE …` sin un solo `JOIN`.** MySQL no
tiene nada entre lo que elegir, así que no hay 1052 y no hay 500 — y una de las
dos lleva encima doce líneas explicando su `<=>`. El contador miraba si el SQL
nombraba `unidades` y si el `alumno_id` iba sin prefijo, **pero no cuántas
tablas hay en el ámbito**.

Otra vez la segunda forma de la regla del CLAUDE.md: **contaba bien el síntoma
—«`alumno_id` sin alias»— y no la causa —«MySQL no sabe de cuál de las dos
hablas»**. Repetir el barrido da los mismos dos.

    desnudas antes:  2   (las dos falsas)
    desnudas después: 0

Y el resto del inventario **no se mueve**: de las 170 filas del `--csv` cambian
esas dos, y sólo en la columna `desnudas` — el `estado` de las dos sigue igual.

> **El filtro se queda corto a propósito.** Dice «puede haber más de una tabla»,
> no «hay dos con esta columna». Lo segundo pide el esquema, y el volcado
> congelado **no tiene** `unidades.alumno_id`, que entró por migración: sería
> medir donde la candidata no existe. Quedarse corto deja un candidato de más
> —que es lo que este detector devuelve—; pasarse escondería un 500, que es el
> fallo contrario y peor.

Por eso el control ancla **las dos direcciones**, y no sólo la que se arregló:
que el `JOIN` con `notas` y la coma de 2006 **sigan contando 1**. Comprobado en
rojo contra el código viejo: sin el filtro fallan los dos casos de una tabla y
ninguno de los otros tres.

## 3. Y salió una quinta, de la misma familia y con OTRO arreglo

Al arreglar la cuarta, la pregunta obvia es la tercera rama: `con-igual` también
exige `\b<alias>\.`. Medido: **un solo sitio**, y es real —
`BoletinIndependienteController:455` `motivoDelVacio`, que pregunta *«¿tiene
unidades SUYAS vaciadas?»*. Eso **afirma propiedad**, o sea `=` por la §1.6
partida en dos que formuló el lote D; y salía como `estado = no`, es decir en la
lista de «hay que acotarla».

**Pero el arreglo no puede ser el mismo, y ésta es la parte que importa.**
`alumno_id =` es comunísimo con otras tablas —`m.alumno_id = :id` de
`matriculas`, el de `notas`—, así que hacer el prefijo opcional a secas daría
por acotado lo que no lo está. **Contar de menos esconde trabajo y contar de más
sólo cuesta una revisión**, así que el sin-prefijo se acepta únicamente cuando
el ámbito tiene **una sola tabla** — donde no hay otra que pueda aportar esa
columna. Es el mismo predicado que arregló las «desnudas», reutilizado.

    pendientes antes de la quinta: 22 en 10 sitios
    después:                       21 en  9 sitios

El control ancla las dos direcciones: el `=` sin alias con una tabla es
`con-igual`, y con dos tablas **sigue siendo `no`**.

### El riesgo residual de la cuarta, dicho para que no se descubra solo

La rama del `IS NULL` sí quedó con el prefijo opcional a secas, que es lo que se
diagnosticó y lo que se pidió. Tiene la misma laxitud teórica: un
`m.alumno_id IS NULL` de otra tabla contaría como alcance de `unidades`.
**Medido: cero sitios en `app/`** — ningún `<alias>.alumno_id IS NULL` con un
alias que no sea el de `unidades`. Queda dicho aquí porque el día que aparezca
uno, el sitio donde mirar es esta línea y no el código.

## 4. Resumen de las cifras del detector

| | lecturas pendientes | sitios | desnudas |
|---|---|---|---|
| `main` antes de tocar nada | 26 | 14 | 2 |
| tras la cuarta ceguera | 22 | 10 | 2 |
| tras el ámbito de las desnudas | 22 | 10 | **0** |
| tras la quinta | **21** | **9** | 0 |

**Ninguna lectura entra en la lista en ningún paso.** Los tres cambios sólo
pueden aflojar, y aflojan donde se midió.

## 5. El centinela de `YearsController::postStore`

`tests/Contrato/CentinelaDeLasColumnasDelAnioNuevoTest.php`, a la manera de
`CentinelaDeLosEscritoresDeBitacoraTest` y por el mismo motivo: *una lista a mano
sin centinela dura hasta el siguiente que escriba.*

### La población, que es la mitad del asunto

    68  columnas vivas de `years` (SHOW COLUMNS)
    61  las escribe postStore
     6  estructurales
     1  `firmantes_acta`, que nace vacía a propósito
    ──
     0  sin decidir

**61 y no 60**: la 61ª es `puestos_con_bol_independiente`, que la arregló el lote
E esta misma noche — este centinela llega justo detrás para que no vuelva a
pasar.

Y las cuatro que han entrado a `years` por migración desde el volcado congelado,
que son las candidatas de verdad:

| Columna | ¿Se acordó de la lista? |
|---|---|
| `usa_consecutivo_certificados` | **sí** — copiada con su contador (21 §2.3) |
| `usa_folio_certificados` | **sí** — ídem |
| `firmantes_acta` | **no**, y es a propósito |
| `puestos_con_bol_independiente` | **no**, y era el fallo |

**Dos de cuatro.** Ésa es la tasa que este test convierte en cuatro de cuatro.

### Por qué `SHOW COLUMNS` y no el volcado

Medido: el volcado tiene **64** y la tabla viva **68**, y las cuatro de
diferencia son **exactamente las cuatro de la tabla de arriba** — o sea justo las
candidatas a olvidarse. Un centinela medido contra el volcado mediría donde
ninguna candidata puede aparecer, y saldría verde para siempre.

### Las excepciones, en dos clases y con el porqué dentro

- **Estructurales (6)**: `id`, `created_at`, `updated_at`, `deleted_at`,
  `deleted_by`, `updated_by`. **Seis y no siete: `created_by` SÍ se escribe**,
  porque no lo pone Eloquent — es de este proyecto y dice quién creó el año. De
  las tres de papelera y rastro, copiarlas del año anterior no sería un olvido
  sino un error de otro tipo: heredar el borrado de un año pasado, o decir que
  editó el año alguien que no ha entrado todavía.
- **Nacen vacías (1)**: `firmantes_acta`, decisión de Joseth del 31 ago 2026 —
  *los firmantes se confirman cada año a propósito*, y **un acta firmada por
  quien ya no está es peor que un acta sin firmantes**: el hueco se ve la primera
  vez que alguien imprime y la firma de más no la ve nadie hasta que importa.

> **El modo de fallo que hay que evitar aquí no es el falso positivo: es que la
> lista se convierta en un `@ignore`.** Ante un rojo, la salida barata es meter
> la columna en `NACEN_VACIAS`, que es exactamente lo contrario de lo que el test
> existe para forzar. Por eso la constante lleva escrito que **añadir una entrada
> es tomar una decisión sobre el colegio**, y por eso hay un segundo caso —
> `ninguna_excepcion_sobra`— que cae si una excepción se refiere a una columna
> que ya no existe **o que sí se copia**. Una excepción caducada no da ningún
> error por sí sola: se queda ahí y el siguiente la da por vigente.

### Comprobado en rojo, las tres direcciones (§1.4)

| Qué se rompió a mano | Qué dijo |
|---|---|
| quitada la copia de `puestos_con_bol_independiente` y la de `texto_acta_eval` | falla **nombrando las dos**, con las dos salidas escritas |
| excepción a una columna que no existe | «sobra de la lista: se lee como vigente» |
| excepción a una columna que **sí** se copia | «una de las dos cosas está mal, y la primera que mirar es la lista» |

### Dos trampas de medición que llevan su aviso dentro del test

1. **El bloque alinea con tabuladores**: un patrón con espacios se come nueve
   líneas y da **50** en vez de 61 — una cifra creíble, que es lo peligroso. El
   test lo defiende con una aserción de población que dice dónde mirar.
2. **No todo `$year->x` es una columna**: `postStore` cuelga además `periodos` y
   `grupos_ant` del objeto para armar la respuesta. **No se filtran por nombre**
   —eso envejece—: se cruzan con `SHOW COLUMNS`.

### Lo que este centinela NO comprueba, dicho para que nadie lo lea de más

Comprueba que **cada columna viva está nombrada**, no de dónde sale su valor.
Una columna nueva escrita como `Request::input('x')` lo pasa, y debe pasarlo
—pedirla en el cuerpo también es una decisión—, pero eso **no significa que se
herede**. Si lo que hace falta es que el año nuevo la traiga del anterior, se
comprueba mirando el resultado, no esta lista.

## 6. Lo que hay que llevarse de este lote, y no es el arreglo

Los dos encargos eran de instrumento, y los dos terminaron en el mismo sitio:

**El detector que gobernaba el reparto de la noche era el único sin control.**
`unidades-sin-alcance.py` repartió el trabajo de cinco sesiones y no estaba en
`AutopruebasDeLasHerramientasTest` ni tenía `--control` que registrar. Se
equivocó cinco veces, las cinco contando de más, y las cinco las encontró una
persona con el fichero delante. **Contar de más no se delata solo**: la lista
gana sitios donde no hay nada, quien los revisa los cierra «decidiendo no
tocarlos» —que aquí es una salida legítima, §1.5— y el instrumento nunca queda
mal. Ahora tiene trece formas ancladas.

**Y la lista de columnas del año nuevo llevaba dos de cuatro.** No por descuido
de nadie: porque una lista a mano de 61 entradas, escrita en un sitio y
consultada en ninguno, no tiene forma de avisar. Su tasa medida —dos de las
cuatro columnas que entraron por migración— es lo que justifica el centinela,
no la que faltaba.

**Y las dos condiciones del encargo se cumplieron contestando distinto de lo que
pedían**, que es lo que estas dos noches han enseñado a mirar:

- *«corre `AutopruebasDeLasHerramientasTest`, ese test existe para esto»* — corría
  y no comprobaba nada de esto; había que **añadir** la herramienta;
- *«la población es 43 en 23»* — era 26 en 14 antes de tocar nada, porque se
  midió antes de fundir cinco lotes.

Ninguna de las dos era un error que costara nada; las dos habrían hecho dar por
comprobado algo que no lo estaba.

## 7. El control nuevo NO depende del árbol — medido, no razonado

La pregunta la hizo la coordinación antes de fundir, y es la correcta: el caso de
`consultas-en-bucle.py` falla en los cinco worktrees **porque mira el árbol**
(llama a `git show` y el `.git` de un worktree apunta al host). Si el control
nuevo tuviera cualquier dependencia parecida, entraría a `main` un rojo nuevo.

**No la tiene, y no es una opinión sobre el código:**

| Desde dónde | `exit` |
|---|---|
| `/tmp`, sin ningún `./app` delante | **0** |
| la raíz `/app` dentro del contenedor | **0** |
| el worktree `.worktrees/g` | **0** |

Y las tres salidas tienen **el mismo `md5`** (`8a02c6ca…`), que es lo que
descarta que coincida el código de salida por otro camino.

El porqué está en la forma del control: **sus entradas son las constantes
`CASOS_DE_CONTROL` y `CASOS_DE_DESNUDAS`, trece cadenas literales**. No abre
ficheros, no llama a `git`, no mira el `cwd` y no toca la base — y la rama del
`--control` está **antes** del `os.path.isdir(RAIZ)` de `main()`, así que ni
siquiera exige que exista `app/`.

> **Es la otra mitad de la decisión de anclar formas y no un número del árbol.**
> Un control anclado en «43 pendientes» no sólo envejece cada noche: además
> **hereda todas las maneras que tiene un árbol de estar en otro estado** — el
> worktree, el clon superficial del CI, el `cwd` de una shell. Las tres ya se han
> pagado en este repo, y las tres con la misma respuesta: *mira desde qué árbol
> corre*. Éste no tiene desde dónde correr.

> **Y un aviso de método, que es lo que costó comprobarlo:** las dos primeras
> medidas salieron con el `exit=` **vacío**, porque en zsh `PIPESTATUS` empieza
> en 1 y `${PIPESTATUS[0]}` no es nada. Es la §1.8 en su forma exacta —un `exit`
> que no es el del programa— y se cayó sola en la comprobación de que no hace
> falta que se caiga en una suite: **sin tubería no hay nada que confundir**.

## 8. `tools/independientes-sin-estructura.php` (§9.1)

Contesta **qué pares (alumno, asignatura) van por boletín aparte y no tienen ni
una unidad propia**. Es el único riesgo del módulo que **no avisa de ninguna
forma**: la definitiva sale 0, el boletín en blanco, y la consulta no falla —
devuelve cero filas, y cero filas se leen como cero.

### Y hoy es peor que cuando se escribió el plan

Con la fase 1 fundida, un marcado sin unidades propias **ni siquiera aparece** en
notas perdidas: la consulta pide `u.alumno_id <=> ALCANCE` y no empareja nada.
**El arreglo del alcance cambió el síntoma de sitio, y a peor**: antes la
pantalla le acusaba de perderlo todo —falso, pero visible—; ahora se lo calla. Un
alumno acusado de algo raro se mira; uno que no sale, no.

### El `=` de aquí NO es el fallo caro de la §3

Cae del lado derecho de la §1.6 partida en dos: la pregunta es **«¿cuáles son
SUYAS?»**, que afirma propiedad. Con `<=>` un alumno sin nada propio emparejaría
con las del grupo y saldría con estructura — o sea que el null-safe **no daría un
número peor: daría cero huecos siempre**, que es justo la respuesta que archiva
el asunto. Por eso el control lo ancla con un caso propio.

### La población, y qué significa hoy

Hoy es **cero marcados**, así que la herramienta **no puede** decir «todo bien».
Dice otra cosa, y con esas palabras: *«no hay a quién revisar; el módulo no está
en uso»*. Con marcados dice `N marcados en P periodos; A asignaturas; X pares
revisados`, que es la forma que pide el plan.

### Comprobada de verdad, y en las dos mitades

El `--control` ancla la parte pura —cinco formas literales, sin base y sin
árbol—; **el SQL no lo comprueba ningún control**, así que se ejercitó a mano
sobre la base de tests, fabricando el caso y borrándolo después:

| Montado | Huecos | Qué demuestra |
|---|---|---|
| nada | **10** de 10 | el barrido encuentra |
| una unidad **suya viva** | **9** | tapa sólo la que es suya |
| una **del grupo** (`alumno_id NULL`) | sigue **9** | las del grupo **no** tapan |
| una **suya borrada** | sigue **9** | una estructura vaciada es un hueco |
| `aplica = 0` | 0 marcados | la marca apagada no se revisa |

Base devuelta a cero: `0` unidades con dueño, `0` filas en `bol_ind_periodos`.

Y el control, en rojo contra la forma ingenua —hacer que las del grupo cuenten
como suyas— cae en exactamente un caso: *«las unidades DEL GRUPO no tapan el
hueco»*.

### Dos cosas que salieron al medir y no estaban en el encargo

1. **La primera versión reventaba y salía `exit=0`.** Se equivocó de tabla
   (`personas` no existe: `alumnos` ya lleva `nombres` y `apellidos`), y el
   bootstrap de Laravel **pinta la excepción muy bien y devuelve cero** — o sea
   que quien la llamara desde un script la daría por buena. Es
   `respuestas-que-mienten.py` en forma de herramienta. Ahora la parte de base va
   en un `try` y sale **2** —*no se pudo mirar*—, que son las mismas tres salidas
   del runner de autopruebas: 0 pasa, 1 hallazgo, 2 no concluye.
2. **La línea de la base no puede afirmar cuál es.** Decía «la del `.env`, NO la
   de tests», y con un `DB_DATABASE=` delante corre contra otra: mentiría
   justo en el caso en que importa. Ahora dice «la que resuelve la
   configuración», que es verdad siempre.

## 9. El rojo estructural: las dos mitades

`AutopruebasDeLasHerramientasTest` llevaba toda la noche con **un rojo permanente
en los cinco worktrees**, descontado a mano en cada parte. Un rojo que se
descuenta a mano es ruido, y el ruido tapa el siguiente.

La causa: el `.git` de un worktree es un **fichero** que dice
`gitdir: /Users/…/8myvc/.git/worktrees/<x>` — una ruta **del host**, que dentro
del contenedor no existe. `consultas-en-bucle.py --control` llama a `git show` y
no puede concluir.

Son **dos** arreglos y no uno, porque son dos cosas distintas: uno quita la causa
**en los árboles nuevos**, el otro hace que el control **diga la verdad** en los
viejos. Ninguno sustituye al otro.

### 9.1 · La causa: el `gitdir` relativo (`tools/worktree-de-sesion.sh`)

No es escribir la ruta del contenedor —eso arreglaría el contenedor **rompiendo
el host**—. Es escribirla **relativa**, que git resuelve desde el directorio que
contiene el `.git`, y por eso vale en los dos a la vez:

    gitdir: ../../.git/worktrees/<x>

Medido en las cuatro direcciones antes de tocar nada:

| | |
|---|---|
| `git rev-parse` **dentro** del contenedor | `/app/.worktrees/g` (antes: `fatal: not a git repository`) |
| `git show 2837171^`, que es lo que pide el control | **exit=0** |
| `git status` / `log` **en el host** | siguen bien |
| `git worktree list` desde la raíz del host | lista los ocho árboles |

**La cuarta es la que importa** y es la que convierte esto de idea en arreglo
fundible: descarta que se arregle el contenedor rompiendo el host.

**No arregla los árboles ya creados**, y eso va escrito en el script con la línea
para arreglar uno a mano — porque las tres sesiones vivas se enteran por un
mensaje, y quien venga dentro de un mes lo va a leer del script.

### 9.2 · Que el control diga la verdad en los árboles viejos

La otra mitad, y es la que el runner necesitaba de todas formas: **cuando un
control sale 2, el test mide si `git` resuelve desde ese árbol**. Si no resuelve,
se salta **diciendo la medición y cómo arreglarlo**; si resuelve, falla como
siempre.

**Un skip en silencio es peor que un rojo**, así que no lo hay: el mensaje trae
las tres cosas que midió, lo que dice el `.git`, y la línea del arreglo.

**Y una lista fija no servía aquí — ya se probó y se cayó al fundir**: en el
worktree el caso pasaba, en `main` la excepción sobraba y el caso caía. Está
contado en la cabecera del propio test. Es la misma familia que todo lo de esta
noche: **un criterio anclado al árbol hereda todas las maneras que tiene un árbol
de estar en otro estado.** La medición no.

Las tres condiciones se miden **juntas** a propósito — existe `.git`, **es un
fichero**, y `git rev-parse` falla — porque la causa conocida es *«esto es un
worktree y su `.git` apunta fuera»*, no *«git falló»*. Un control que salga 2 por
otro motivo **con git funcionando sigue fallando**, que es lo que impide que esto
se vuelva un cajón. Y el skip **desaparece solo** el día que el árbol se arregle,
en vez de quedarse de adorno.

Comprobado en las dos ramas, sobre el mismo árbol y cambiando sólo el `.git`:

| `.git` del árbol | Resultado |
|---|---|
| `gitdir: ../../.git/worktrees/g` | **7 passed**, `exit=0` — el control **se ejerce** |
| `gitdir: /Users/…/8myvc/.git/worktrees/g` | **1 skipped, 6 passed** — con la medición delante |

> **Aviso de contabilidad entre lotes:** mi árbol ya lleva el `gitdir` relativo,
> así que **mi `AutopruebasDeLasHerramientasTest` da 7/7 y los otros lotes 6/7**.
> No es que el mío pase por otra razón: es que en mi árbol `git` resuelve.

### 9.3 · Lo que la prueba del script destapó, y por eso lleva una guarda

La primera versión escribía el `gitdir` relativo **a secas**, y al probarla se
cayó: este script hace `cd "$(dirname "$0")/.."`, así que lanzarlo por su ruta
dentro de un worktree —`.worktrees/g/tools/worktree-de-sesion.sh`, que es
exactamente lo que hice— **crea el árbol nuevo dentro de ése**. Y ahí `../../.git`
es el `.git` de un worktree: un **fichero**, no una carpeta con `worktrees/`
dentro. El árbol nuevo nacía sin git **en los dos sitios**, que es peor que el
problema que el arreglo quita.

Por eso la reescritura va detrás de `git rev-parse --git-common-dir`, que es lo
que **distingue** el árbol principal de un worktree en vez de suponerlo; si no lo
es, avisa y deja el `.git` que escribió git. **La suposición era justo lo que
fallaba**, y no se habría visto sin ejecutarlo: el script imprime sus cinco pasos
en verde igual.

Probado de punta a punta creando un árbol de usar y tirar desde la raíz:

    .git ->  gitdir: ../../.git/worktrees/zz
    git rev-parse DENTRO del contenedor   ->  /app/.worktrees/zz
    git show 2837171^ DENTRO              ->  exit=0
    git log en el HOST                    ->  bien;  git worktree list, los nueve

Borrado después: el árbol, su rama y su base `simonbolivar_testing_zz`. **Y el
`tools/worktree-de-sesion.sh` de la raíz quedó como estaba** — se tocó sólo para
la prueba y se restauró; el del commit es el de este árbol.

## 10. EL CENSO DE LA FASE 1 — medido sobre `main` con todo fundido

> **Qué se midió, que es lo que le faltó a la cifra que se propagó tres veces:**
>
> | | |
> |---|---|
> | Árbol | `main`, raíz del repo, **sin modificar** (`git status` limpio) |
> | Commit | **`da26efb`** — *merge(lote D · fase 4 completa): la ruta 547* |
> | Fecha | **1 sep 2026, 07:53:33 -0500** |
> | Instrumento | `tools/unidades-sin-alcance.py` de `fix/bi-lote-g` (`812e1a0`), **con las cinco cegueras cerradas y su `--control` verde** |
>
> La cifra anterior —«43 en 23»— no envejeció: **se midió antes de fundir cinco
> lotes y se copió después de cada fusión sin remedirla**. Por eso la fecha y el
> hash van arriba y no al final.

### 10.1 · La tabla

| | Lecturas |
|---|---|
| **Total de referencias a `unidades`/`subunidades`** | **176** (169 lecturas + 7 escrituras) |
| bien por construcción | 85 |
| **hay que acotarla** | **80** |
| no se sabe | 4 |

Y dentro de las 80:

| Estado | Lecturas |
|---|---|
| **acotadas** (`<=>` o `IS NULL`) | **53** |
| **`con-igual`** (`=`, que afirma propiedad — §1.6) | **6** |
| **sin acotar** | **21**, en **9 sitios** |

### 10.2 · Las 21 sin acotar, una a una y clasificadas

**El criterio no es «0 en la columna»** —lo cerrado decidiendo no tocarlo se
queda contado ahí— **sino que cada fila esté acotada o tenga una decisión
escrita.** Las nueve están leídas con el fichero delante:

**(a) Acotadas de otra forma que el detector no ve — 2 sitios, 3 lecturas**

| Sitio | Cómo está acotada |
|---|---|
| `Grupo.php:227` `marcarLosQueTienenDatosPropios` | `u.alumno_id IN (?, ?, …)` — es la lista de los alumnos del grupo, o sea alcance que **afirma propiedad**. El detector mira `<=>`, `IS NULL` y `=`, y **no `IN`** |
| `DefinitivasDeAsignatura.php:523` `calcular` | acota con **`c.dueno <=> ALCANCE`**, donde `dueno` es `u.alumno_id` renombrado dentro de una derivada. El detector busca `u.alumno_id <=>` y ahí ya no se llama así |

**(b) Decididas a propósito, con el porqué escrito en el propio código — 6 sitios, 16 lecturas**

| Sitio | Por qué NO se acota |
|---|---|
| `EnviarNotificaciones.php:212` `avisosDeNotas` | marcar a un alumno **no le borra las notas que ya tiene en las subunidades del grupo**; acotar perdería el aviso de un cambio real, en silencio |
| `InformesController.php:132` `grupos_desactualizados` | acotar dejaría que la nota de un independiente **no marcara nada** y se serviría una definitiva vieja sin error. Y **escondería justo al alumno de la §9.1** |
| `UnidadesController.php:456` `getTrashed` | es «qué hay en la papelera»: la respuesta correcta las incluye a todas. Es **el único sitio desde el que se ve** una unidad borrada de un independiente |
| `DefinitivasDeAsignatura.php:318` `recalcular` | pregunta **por dueño a propósito** — `SELECT DISTINCT alumno_id`: quiere saber qué boletines tienen unidades, y acotarla reintroduce el cero de la §9.1 |
| `DefinitivasDeAsignatura.php:619` `selloDeVersion` | **sello de caché**: sin acotar recalcula de más (cuesta tiempo); acotado **sirve un dato viejo sin un error en el log** |
| `DefinitivasDeAsignatura.php:785` `estadoDelGrupo` | ídem |

**(c) Código muerto, anotado — 1 sitio, 2 lecturas**

`NotaFinal.php:315` `calcularAsignaturaPeriodo`. **Comprobado hoy, no heredado:**
`grep` sobre `app/` y `routes/` no devuelve **ni un llamador** — la única
aparición del nombre es el comentario que ya lo dice. La §1.5 es explícita: *no
se acota código muerto, se anota*.

    3 (acotadas de otra forma) + 16 (decididas) + 2 (muerto) = 21 ✓

### 10.3 · El veredicto

> **Pendientes de verdad: CERO.** Las 21 están **acotadas de otra forma (3),
> decididas con el porqué escrito (16) o son código muerto anotado (2)**. No
> queda ninguna fila de la fase 1 esperando trabajo.

**Y las tres cosas que hacen que este censo valga y los anteriores no:**

1. está medido **después** de arreglar el instrumento — con el detector viejo,
   cuatro de estos nueve sitios salían en la lista de trabajo **estando
   acotados**;
2. lleva **fecha y hash de lo medido**, que es lo único que distingue una cifra
   viva de una copiada;
3. **cada fila está leída**, no contada. El bucle que hace peligroso lo contrario
   está en la §6: la §1.5 absorbe los falsos positivos del detector como
   «decisiones de no tocar», y así **un detector que cuenta de más nunca queda
   mal**.

### 10.4 · Los dos límites del detector que este censo deja medidos

No son cegueras nuevas que haya que arreglar a ciegas: son **las dos formas en
que el detector sigue contando de más**, y ahora están nombradas.

- **No reconoce `IN (…)` como alcance.** Es la misma familia que la quinta —una
  lectura que afirma propiedad— y es de una línea. **Se arregla abajo.**
- **No sigue un alias renombrado dentro de una derivada** (`u.alumno_id AS
  dueno` … `c.dueno <=> …`). Eso **no** se arregla con un regex: exige entender
  la consulta. Queda escrito como límite conocido, que es lo que corresponde
  cuando el arreglo costaría más que la lectura a mano.

## 11. La corrida real de `independientes-sin-estructura.php`, y **contra qué**

Corrida el **1 sep 2026** desde la raíz, sobre la base que resuelve el `.env`:

```
Base: simonbolivar (la que resuelve la configuración; de serie el `.env`, que NO es la de tests)

Población: 0 alumnos marcados (`bol_ind_periodos` con `aplica = 1`); 0 pares revisados.

NO es «ningún alumno se cae por el hueco»: es que **no hay a quién revisar**.
El módulo del boletín independiente todavía no está en uso — la tabla nace
vacía y así sigue. La primera marca que se ponga hace que esto conteste algo.
exit=0
```

**Y aquí va el matiz que corrige mi propia frase**, porque es exactamente la
clase de cifra que esta noche se ha propagado mal tres veces:

> **Esto NO es «nadie está marcado en ningún colegio».** Es **una** base, y ni
> siquiera es una de las quince: es la copia local de desarrollo. En este MySQL
> sólo hay `laravel` y `simonbolivar` — **los quince colegios viven en el
> servidor de producción**, cada uno con la suya (CLAUDE.md, «Despliegue»).

Lo que sí se puede afirmar con esta medida, y nada más:

- en **la base de desarrollo**, hoy, **cero marcados y cero pares revisados**;
- y como la marca se pone por una ruta que **todavía no está desplegada**, no hay
  ningún camino por el que un colegio pueda tener filas en `bol_ind_periodos`
  antes de que se despliegue.

**Lo segundo es un argumento, no una medición**, y por eso va dicho aparte del
número. La medición son quince corridas el día del despliegue, no una hoy.

> **Y qué va a contestar el día del despliegue, para que nadie lo lea como un
> fallo:** las migraciones del boletín independiente **no están puestas en
> producción**. Contra un colegio sin ellas, `bol_ind_periodos` no existe y la
> herramienta sale **`exit=2` · NO CONCLUYENTE**, diciendo que no ha revisado ni
> un par. **Eso es correcto y es el diseño**: la alternativa —un `0` limpio— sería
> la respuesta que archiva el asunto justo en el colegio donde no se ha mirado
> nada.

## 12. La sexta ceguera: `IN (…)` — y lo que hay que escribir al lado de la cifra

La encontró **el censo**, leyendo a mano las 21 que quedaban:
`Grupo::marcarLosQueTienenDatosPropios` pregunta `u.alumno_id IN (?, ?, …)` con
la lista de los alumnos del grupo. Eso **es alcance y afirma propiedad**, igual
que el `=`, así que va en la rama `con-igual` y con las mismas dos puertas —
prefijo explícito siempre, sin prefijo **sólo con una tabla en el ámbito**.

| | Lecturas | Sitios |
|---|---|---|
| antes | 21 | 9 |
| después | **20** | **8** |

**Una sola fila cambia** (`Grupo.php:227`, `no → con-igual`) y **ninguna entra**.
En rojo contra el código viejo: fallan los dos casos del `IN` y ningún otro.

> **Y ésta es la frase que tiene que viajar con la cifra, porque sin ella el 20
> se lee al revés: NINGUNA CONSULTA SE HA TOCADO.** La fase 1 no ha avanzado un
> paso entre las dos medidas — lo que cambió es **el instrumento**. Un lector que
> vea «21 → 20» sin esto entiende «se acotó una más», que es exactamente lo
> contrario de lo que pasó: **esa consulta ya estaba acotada desde que la escribió
> el lote D, y el detector llevaba desde entonces contándola como trabajo.**
>
> Es la sexta vez que este detector cuenta de más y **las seis en la misma
> dirección**. Ya no es una anécdota: es la firma de un instrumento que sólo puede
> equivocarse hacia un lado, y por eso su cifra **nunca es una cota inferior del
> trabajo que queda — es una cota superior**.

### Por qué este arreglo va en un commit aparte del censo

Porque **mueve una cifra ya publicada**. Fundidos juntos, el censo y su
corrección serían indistinguibles y **nadie podría volver a comprobar el 21 en
9**. Separados queda escrito que el censo dijo 21 en 9 **con el instrumento de
ese día**, y que después el instrumento mejoró. Es justo lo que les faltó a las
tres cifras que se propagaron mal esta noche. *(Formulación de la coordinación,
1 sep 2026; la comparto entera.)*

### El veredicto de la fase 1 NO cambia

Sigue siendo **cero pendientes de verdad**. Esa fila ya estaba clasificada en la
categoría **(a) acotada de otra forma que el detector no ve** — lo único que ha
pasado es que ahora el detector la ve, así que **se muda de la columna «sin
acotar» a la columna «con-igual»**. El desglose queda:

    (a) acotadas de otra forma que el detector no ve   1 lectura  / 1 sitio
        `DefinitivasDeAsignatura::calcular` — el alias renombrado en la derivada
    (b) decididas a propósito, con el porqué escrito  16 lecturas / 6 sitios
    (c) código muerto anotado                          2 lecturas / 1 sitio
                                                      ──────────────────────
                                                      20 lecturas / 8 sitios ✓

## 13. EL CENSO DE LAS HERRAMIENTAS — quién comprueba a los que miden

> Medido el **1 sep 2026** sobre `main` en `fff3b64`, árbol limpio. La pregunta
> es la generalización de lo que le pasó a `unidades-sin-alcance.py`: **repartió
> el trabajo de una noche entera y era la única sin nadie que la comprobara.**

### 13.1 · La población, remedida — y no coincide con la lista

| | |
|---|---|
| Ficheros en `tools/` | **38** (+1 directorio, `plantillas`) |
| **de medición** | **29** |
| operativas / generadoras | 9 |
| Filas en la tabla de `CLAUDE.md` | **15** (que nombran 17 herramientas) |
| **De medición y NO en esa tabla** | **13** |

Las trece que faltan: `alcance-en-los-traspasos.py`,
`escrituras-crudas-con-entrada.py`, `escrituras-sin-auditoria.php`,
`fase-cero-de-los-dieciseis.php`, `historial-que-cuenta-de-menos.php`,
`independientes-sin-estructura.php`, `metodos-sin-camino.py`,
`quien-escribe-de-verdad.py`, `salud-de-la-bitacora.php`,
`salud-de-las-definitivas.php`, `tablas-calientes.php`,
`unidades-sin-alcance.py` y `verbos-que-mienten.py`.

**No es que la tabla esté vieja: es que nunca fue un inventario.** Ninguna de las
trece es reciente — `salud-de-las-definitivas.php` gobierna una corrección de
datos de producción y nunca estuvo. *(`CLAUDE.md` es del proyecto y no de la
noche: no lo toco.)*

### 13.2 · Cuáles tienen control, verificado ejecutándolo

**Nueve de veintinueve**, y **las nueve concluyen** (`exit=0`) hoy:

| Herramienta | En el runner |
|---|---|
| `consultas-en-bucle.py` · `escrituras-sin-auditoria.php` · `quien-escribe-de-verdad.py` · `secciones-citadas.py` · `verdad-laxa-que-escribe.py` · `unidades-sin-alcance.py` · `independientes-sin-estructura.php` | **sí** (7) |
| **`alcance-en-los-traspasos.py`** · **`tablas-calientes.php`** | **NO** (2) |

> **Los dos huérfanos son el hallazgo barato de este censo.** Tienen control
> ejecutable, **sano y verde**, y **nadie lo invoca** — que es literalmente lo que
> el docblock del runner dice que no puede pasar: *«un control positivo que nadie
> ejecuta es una intención, y uno ejecutable que nadie invoca es exactamente lo
> mismo, sólo que parece mejor.»* **Conectarlos son dos líneas.**

### 13.3 · Cómo se midió, y las dos veces que el censo se equivocó a sí mismo

Detectar «tiene control» por regex **falló dos veces, una en cada dirección**:

- dijo que **`tablas-calientes.php` no tenía** — y tiene `--autoprueba`, `exit=0`.
  Ramifica con `isset($opciones['autoprueba'])`, no con `--autoprueba`;
- dijo que **`independientes-sin-estructura.php` no tenía** — la escribí yo, y usa
  `in_array('--control', $argumentos)`: la variable se llama `$argumentos` y el
  regex exigía `$argv`.

**La segunda es la peligrosa**, y es al revés que las seis del otro detector:
**contaba de MENOS**. Habría dicho «esta no tiene control» y alguien habría
escrito uno duplicado. Así que el censo se hizo en dos pasos: un filtro **laxo a
propósito** —cualquier línea de código que nombre el flag— y **verificación
ejecutando las nueve**. *La regla de la casa aplicada al propio censo: el primer
sitio donde mirar cuando el número sale raro es el detector, y aquí el detector
era mío y de hace cinco minutos.*

### 13.4 · Las de medición SIN control, ordenadas por lo que decide su cifra

**(A) Su cifra gobierna una decisión PENDIENTE — 3**

| Herramienta | Qué cifra suya se usa, y dónde |
|---|---|
| **`salud-de-las-definitivas.php`** | **11.988 definitivas que deberían existir y no existen**, 718 que discrepan, 1 duplicada (`ESTADO-ACTUAL.md`). **De ese número depende si el arreglo lleva corrección de datos** — y no es informativo: la fase 2 pone un índice único y cada duplicado sería un 500. **Y su propia cabecera dice que ya se corrigió una vez porque MEDÍA DE MENOS.** |
| **`salud-de-la-bitacora.php`** | **18 de 3.229 ingresos con algo que enseñar** (99,4%), y decide si se puede reinterpretar la historia vieja (`ESTADO-ACTUAL.md`). Tiene `CentinelaDeLosEscritoresDeBitacoraTest`, **que vigila su población de escritores pero no su clasificación** |
| **`fase-cero-de-los-dieciseis.php`** | es **la acción del despliegue**: una visita a los quince colegios, y de su salida sale qué se migra dónde |

**(B) Su cifra vive en un plan abierto — 7**

`identificadores-del-cuerpo.py` (5 planes), `respuestas-que-mienten.py` (6),
`cobertura-de-rutas.py` (7), `interruptores-que-nadie-lee.py` (5),
`coste-del-recalculo.php` (5), `indices-que-faltan.php` (4),
`escrituras-en-las-notas.py` (3).

**(C) Su cifra no la usa nadie hoy — el resto**

`guardas-sin-respaldo.py` (**cero** citas en `docs/`), `metodos-sin-camino.py`,
`verbos-que-mienten.py`, `escrituras-crudas-con-entrada.py`,
`historial-que-cuenta-de-menos.php`, los cuatro de rutas, `consultas-lentas.py`,
`auditar-autenticacion.php`, `inventario-autorizacion.py`.

### 13.5 · El criterio: cuáles merecen control y cuáles no

**Merece control la que cumple las tres.** Con dos no basta:

1. **su salida es un número o una lista que alguien usa para decidir o repartir**
   — no basta con que exista;
2. **puede equivocarse en silencio**: su error no rompe nada, sólo cambia la
   cifra. (Las nueve cegueras de esta noche son de esta clase.)
3. **comprobar su cifra a mano cuesta más que escribir el control una vez.**

Y la regla contraria, que es la que ahorra trabajo: **si la herramienta ACTÚA en
vez de medir** —crea un árbol, reconstruye una base, reescribe modelos— **su
comprobación es su efecto y ya la tiene**. `worktree-de-sesion.sh` imprime desde
dónde carga las clases: **eso ya es su control**, y un `--control` encima sería
ceremonia. Las nueve operativas quedan fuera por esto, no por descuido.

**Mi propuesta, con eso: cuatro sí, y en este orden.**

| | Por qué |
|---|---|
| **1. `salud-de-las-definitivas.php`** | cumple las tres **y ya falló una vez midiendo de menos**. Su número decide una corrección sobre datos de producción |
| **2. `salud-de-la-bitacora.php`** | cumple las tres; su reparto UTC/Bogotá es exactamente la clase de clasificación que se equivoca en silencio |
| **3. `identificadores-del-cuerpo.py`** | la más citada de todas (15 ficheros), y su lista **reparte trabajo de seguridad** |
| **4. `respuestas-que-mienten.py`** | 6 planes abiertos; su falso negativo es un método que frena una escritura y contesta 200 |

**Y cuáles NO, con el porqué:**

- **las nueve operativas** — miden su efecto, no una cifra;
- **`guardas-sin-respaldo.py`** — su cifra no la usa **nadie** (cero citas), y
  `CLAUDE.md` ya avisa de que se equivocó en las dos direcciones y de que **cada
  fila se lee**. Un control no cambiaría el protocolo;
- **`cobertura-de-rutas.py`, `indices-que-faltan.php`, `coste-del-recalculo.php`**
  — **no cumplen (2)**: se miden ejecutando la suite o `EXPLAIN` contra la base,
  así que **su error no es silencioso**, se ve en la corrida siguiente;
- **los cuatro de rutas** — ya tienen el control más fuerte que existe aquí: las
  **tres instantáneas** de rutas, que un test compara 1:1.

### 13.6 · Lo que este censo NO promete, y hay que decirlo

**Tener control no es tener el control correcto.** El caso está medido y es de
esta noche: **`escrituras-sin-auditoria.php` tiene autoprueba, está en el runner,
sale verde — y cuenta de menos.** `ESTADO-ACTUAL.md` lo dice: *no puede ver*
`Ausencias`, `Frases`, `FrasesAsignatura` ni `DefinicionesComportamiento`.

Un control **fija la conducta conocida**; no descubre cegueras nuevas. Las seis
de `unidades-sin-alcance.py` no las encontró su control —no existía— y la sexta
la encontró **leer las 21 filas a mano**. Así que escribir estos cuatro controles
**congela lo que hoy sabemos de cada herramienta y no la vuelve fiable**: lo que
la vuelve fiable sigue siendo leer sus filas una vez.
