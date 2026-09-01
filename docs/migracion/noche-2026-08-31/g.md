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
