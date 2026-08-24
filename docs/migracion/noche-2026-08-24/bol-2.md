# BOL-2 — la consulta invariante del boletín final

**Sesión `ad`**, rama `medicion/lote-y-cobertura`. Noche del 24 ago 2026.

El encargo era: test rojo, sacar del bucle una consulta que no depende del bucle,
y dar el número. **Los tres hechos. Y el número no es el que se esperaba, así que
va primero.**

---

## §0 — La conclusión, antes de nada: **el arreglo es real y NO cura el 504**

| | antes | después |
|---|---|---|
| consultas de la petición | **3.762** | **3.355** |
| de ésas, la invariante | **408** | **1** |
| tiempo (mediana, ventana estable) | **1.960,7 ms** | **1.953,2 ms** |

**Quita 407 consultas y no mueve el tiempo de forma medible en este entorno.** Y
eso hay que decirlo con el número delante porque **la primera medición decía otra
cosa**: 6.471,6 ms antes contra 1.892,4 ms después, o sea 3,4×. Alternando las
corridas —antes, después, antes, después— la pareja estable es **1.960,7 / 1.953,2**
e **indistinguible**. El 6.471 llevaba su propio aviso dentro: varianza de 4.437 a
8.521 ms, contra 1.902–1.973 en las pasadas limpias.

> **Si hubiera medido una vez, habría publicado un 3,4× que no existe.** Es la
> lección del *«3× que resultó ser la caché»* del [02](../02-plan-rendimiento.md),
> repetida por tercera vez esta noche y con la misma forma: **el número bueno era
> el aburrido.**

**Lo que esto le hace al plan:** el encargo decía *«si con una línea baja de 63 s a
algo razonable, la fase 2 de definitivas deja de ser el bloqueante de esta
pantalla»*. **No baja.** Quita el 10,8% de las consultas, así que si en producción
el tiempo se reparte por consulta, los 63 s del grupo 105 pasan a ~56 s: **sigue
siendo un 504**. La fase 2 sigue donde estaba y esta pantalla también.

## §1 — Dónde está el tiempo, medido, que es lo que sí decide

De las **3.355** que quedan, en una sola petición sobre 37 alumnos × 10
asignaturas:

```
  1480  SELECT distinct n.nota, n.id as nota_id, …     ← alumnos × asignaturas × periodos
  1122  SELECT COUNT(n.id) as notas_perdidas from …    ← la segunda capa
   370  SELECT nf.*, nf.nota as DefMateria, …          ← alumnos × asignaturas
   148  SELECT * FROM ( SELECT d.id as definicion_id …
    74  SELECT @rownum:=@rownum+1 AS indice, …
    37  ×3 (comportamiento, áreas)
  3355  (21 firmas distintas)
```

**1.480 + 1.122 = 2.602 de 3.355, el 78%, son los dos bucles anidados** —
`Unidad::deAsignatura` y `Subunidad::perdidasDeUnidad`, la «segunda capa» que este
lote tenía prohibido tocar, más el `SELECT distinct` de notas perdidas por
(alumno, asignatura, periodo).

**Ahí está el 504, y bajarlo es rediseñar el informe, no arreglarlo.** Queda
escrito con su número para que la decisión se tome con él delante: **una agregación
por grupo en vez de 2.602 consultas por petición** es el orden de magnitud que hace
falta, y eso es un frente, no una línea.

## §2 — El test rojo, primero y con las dos ramas

[`tests/Contrato/BoletinFinalConsultaInvarianteTest.php`](../../../tests/Contrato/BoletinFinalConsultaInvarianteTest.php).
Cuenta **el trabajo y no el tiempo**, que es la única forma de que sirva: un aserto
de milisegundos dependería de la máquina —la misma suite tardó 2.132 s y 593 s esta
noche— y **el número de consultas no**.

Rojo antes del arreglo, en **las dos ramas del `if ($periodo_a_calcular)`**:

```
sin `periodo_a_calcular`:  408 invariantes de 3.763 consultas
con `periodo_a_calcular`:  408 invariantes de 2.209 consultas
```

**Las dos ramas están en el test a propósito**, por el aviso de `9e` sobre los
predicados que la suite no ejerce: de los caminos que existen, un test ejerce los
que ejerce, y **un «408 consultas» sin decir en qué rama se contó es el número que
alguien usa mañana para justificar otra cosa**. Aquí las dos dan 408 —la invariante
depende de alumnos × asignaturas y no del número de periodos— y el total difiere
porque menos periodos es menos trabajo aguas abajo. **Que coincidan es el
resultado, no el supuesto.**

Y el aserto lleva **las dos mitades**: que las invariantes no pasen de la cota, **y
que el oyente haya visto consultas**. Con una cota de tipo «no más de N», **cero es
el mejor resultado posible**, así que un `DB::listen` desenganchado deja el test en
verde sin medir nada.

### El predicado del conteo estuvo mal tres veces, cada una en una dirección

El número **408 → 1** es correcto, pero **la firma con la que se midió no lo era**,
y se vio al cruzarlo con el gemelo:

| versión | qué daba |
|---|---|
| `'FROM periodos WHERE year_id'`, subcadena del SQL crudo | 408 → 1 (**bien**), pero **ciega a Eloquent**: `Periodo::where('year_id', …)->get()` genera `` select * from `periodos` where `year_id` = ? `` y ninguna subcadena del literal aparece ahí |
| `from periodos` + `year_id`, ancha | 408 → **38**, y **las 37 nuevas eran falsas**: la consulta de comportamiento hace `FROM periodos p LEFT JOIN nota_comportamiento …`, o sea **pasa por `periodos` sin ser «los periodos del año»** |
| ídem **más «sin `join`»** | **408 → 1**, y además reconoce la forma de Eloquent |

> **Estrechar y ensanchar son dos maneras de equivocarse mientras el predicado no
> reconozca la estructura de lo que busca.** Lo que separa la invariante de la de
> comportamiento no es cómo se escriba `periodos`: es que **la invariante no une con
> nada**. Ése era el discriminador que faltaba, y es la misma forma que la de las
> tres vistas del texto en [MED-4 §6](med-4.md).

Y **el predicado se comprueba a sí mismo**, con los tres SQL reales copiados de
`DB::listen` y el del `JOIN` como negativo. Va como test y no como comentario
porque un predicado de conteo que nadie ejerce es **la mitad de la medición sin
comprobar**: si mañana alguien lo estrecha, la cota sigue verde y el número cambia
sin que nada avise.

### El segundo test es el que impide el arreglo ingenuo

`test_cada_asignatura_perdida_conserva_su_propia_cuenta`, escrito **antes** de
tocar el controlador y por una razón concreta: **sacar la consulta del bucle y
asignar el mismo resultado a todas las asignaturas comparte los objetos `periodo`,
y el bucle los muta** — `$periodo->cantNotasPerdidas` en uno,
`$periodoAlone->cant_perdidas` en el otro. Con los objetos compartidos, **todas las
asignaturas acabarían mostrando la cuenta de la última**.

> **Y eso no lo ve ninguna cota de consultas: es un cambio de resultado con el
> mismo número de consultas.** El arreglo correcto es `array_map(clone)`, y este
> test es lo que lo obliga. Es exactamente la versión que sale sola al leer «saca
> la consulta del bucle».

### Comprobado al revés — y cae más de lo que este documento afirmaba

Decir *«este test caza el arreglo ingenuo»* **es una afirmación, y estaba sin
comprobar**: es la mitad positiva sin ejercer, en el sitio donde más cuesta
—justo en la prueba que justifica el `clone`—. Así que se quitó el `clone`,
devolviendo el array cacheado tal cual, y se corrió:

```
⨯ cada asignatura perdida conserva su propia cuenta
⨯ la forma del boletin final de un alumno with data set "bolfinales"
⨯ la forma del boletin final del grupo with data set "bolfinales"
✓ los otros 17

Tests: 3 failed, 17 passed
```

**Cae el test dedicado, y caen además dos snapshots de `BoletinesTest`.** O sea
que la condición del encargo —*«los snapshots verdes sin regenerarlos, y si
alguno se mueve, para»*— **no era una formalidad: era una red que funciona sola**.
El arreglo ingenuo cambia la respuesta, y el cambio **se ve en el snapshot**.

Dos cosas que esto añade a lo que el documento decía:

- **el `clone` está protegido por tres tests, no por uno**, y dos de ellos son
  anteriores a este trabajo. La afirmación *«sin mi test, el arreglo ingenuo
  pasaba»* **habría sido falsa**;
- y **caen los data sets de `bolfinales` y no los de `bolfinales-preescolar` ni
  los de `boletines`**, que es la firma de que el fallo está justo en este camino
  y no en otro. Un fallo que tumbara los veinte diría que el montaje mide otra
  cosa.

## §3 — Y el arreglo tuvo un fallo que encontró un número que no cuadraba

La primera versión guardaba el memo en `private array $periodosPorAnio`. El
informe daba **0 consultas invariantes** donde el test de contrato daba **1**, y un
número que no se entiende no se publica.

**`Illuminate\Routing\Route::getController()` memoiza la instancia del
controlador** en el objeto `Route`, que vive en la colección del router: el
controlador **sobrevive a la petición** en cualquier proceso que atienda más de
una. En php-fpm cada petición es un proceso y no se nota; **en la suite sí**, y ahí
se vio — la pasada descartada llenaba el memo y las medidas leían la caché.

Y un memo de periodos que cruza peticiones **sirve datos viejos** en cuanto alguien
edita un periodo. Así que vive donde este proyecto ya decidió que viven estas
cosas, y por la razón que ya estaba escrita: **los `attributes` de la petición**,
igual que `User::fromToken()` guarda ahí el contexto *«y no en una propiedad del
servicio, que sobreviviría a la petición bajo Octane»* ([02 §4](../02-plan-rendimiento.md)).

## §4 — Dos instrumentos míos que mintieron, y cómo se cazaron

Los dos en el cronómetro
([`tests/Barrido/CosteDelBoletinFinalTest.php`](../../../tests/Barrido/CosteDelBoletinFinalTest.php)),
y los dos con números creíbles:

1. **`DB::listen` registrado dentro del bucle** → **816 consultas invariantes donde
   había 408**. No hay `unlisten`, así que cada pasada dejaba su oyente vivo; y como
   los contadores se **reasignaban** al principio de cada iteración y los cierres los
   habían capturado **por referencia**, todos los oyentes sumaban en las mismas
   variables. Un factor 2 exacto. **Lo cazó tener dos medidas del mismo trabajo**:
   el test de contrato contaba 408 con un solo oyente.
2. **El histograma no se reseteaba por pasada** → el reparto sumaba **13.421**
   debajo de un total de **3.355**, con la cabecera diciendo «de la última pasada».
   Cuatro veces todo. Mismo error un nivel más abajo: **lo que se lee por pasada hay
   que vaciarlo por pasada.**

## §5 — Lo que se comprobó y lo que no se tocó

| | |
|---|---|
| `BoletinesTest`, 17 snapshots | **verdes sin regenerarlos** — es la prueba de que el resultado es el mismo |
| `BoletinFinalConsultaInvarianteTest` | 3 tests, rojos antes y verdes después |
| larastan nivel 7 | `[OK]` con la caché borrada |
| `pint:test` | limpio en su ámbito |

**Y una que hay que decir porque es una regla que rompí y deshice:** le pasé Pint a
`app/Http/Controllers/Informes/BolfinalesController.php` y **ese fichero no está en
el ámbito de Pint** del `composer.json` (`app/Http/Controllers/` sólo entra por
`Concerns`). Convirtió el fichero entero de tabuladores a espacios: **952 líneas
cambiadas** para un arreglo de una consulta, o sea el «diff ilegible» que CLAUDE.md
prohíbe. Revertido con `git checkout --` y el cambio rehecho a mano. **A los
ficheros de fuera del ámbito de Pint no se les pasa Pint**, aunque los toques.

### Lo que NO se toca, y por qué

- **la segunda capa** (`Unidad::deAsignatura`, `Subunidad::perdidasDeUnidad`): es el
  78% de las consultas y es un rediseño. Medido y escrito en la §1;
- **`Informes/CertificadosPersonaController`: las mismas tres consultas
  invariantes, y NO hay nada que arreglar — están en código que no alcanza
  nadie.** Comprobado después de haberlo reportado mal, y así queda escrito:

  | | |
  |---|---|
  | rutas que apuntan a la clase | **una**: `PUT certificados-persona` → `putIndex` |
  | qué hace `putIndex` | doce líneas: **una** consulta de `matriculas`, y devolverlas |
  | ¿llama a `detailedNotasGrupo`? | **no**, ni él ni nadie: el método está definido en la línea 53 y **no lo llama nada** — ni el fichero, ni otra clase (`grep` en `app/` entero) |
  | tamaño | **510 líneas, de las que ~12 son alcanzables** |

  Las tres invariantes están en 85/88, 149/151 y 406/408, o sea **dentro de ese
  subárbol muerto**. La primera versión de este documento decía *«la misma
  estructura, y el arreglo de aquí no le llega, y es la misma línea»*: lo de la
  estructura es cierto —es una copia del `Bolfinales`— y **lo de que haga falta
  arreglarlo es falso**, porque no se ejecuta.

  > **El aviso que se lleva de esto:** dos ficheros pueden ser copia uno del otro
  > y **no ser el mismo problema**, porque lo que decide no es el contenido sino
  > qué lo alcanza. Comparé el código y no el enrutado, y estuve a punto de meter
  > un arreglo en la lista de decisiones de Joseth **para código que nadie
  > ejecuta**.

  Va al [05](../05-codigo-muerto-y-roto.md) como código muerto, no aquí como
  optimización pendiente. Y **no se borra en esta sesión**: cuatrocientas líneas
  son una decisión, y la regla de CLAUDE.md —*sin ruta y roto se borra*— pide
  antes comprobar que ningún cliente llame a algo que resucite ese camino;
- **el otro `BolfinalesController` NO está enrutado pero SÍ está vivo**, y esto es
  una corrección de lo que decía este documento. Hay dos clases con ese nombre
  —`app/Http/Controllers/BolfinalesController.php` y la de `Informes/`—, y de la
  primera no sale ninguna ruta; **pero se alcanza igual**:

  ```
  GET certificados-estudio/certificado-grupo/{grupo_id}   (auth.personal)
    → CertificadosEstudioController::getCertificadoGrupo
      → new BolfinalesController          (sin `use`: resuelve al de App\Http\Controllers)
        → $bol->detailedNotasGrupo($grupo_id, $user)
  ```

  Y **tiene las mismas tres consultas invariantes, escritas con Eloquent**:
  `Periodo::where('year_id', …)->get()` en las líneas **67** (una por llamada),
  **86** (una por alumno) y **267** (una por alumno × asignatura). O sea **el mismo
  problema, en código vivo, en un camino que este arreglo no toca.**

  > **Dos errores míos con la misma raíz, en direcciones opuestas.** Con
  > `CertificadosPersonaController` dije «hay que arreglarlo» y estaba muerto; con
  > este gemelo dije «está muerto» y está vivo. Las dos veces juzgué la
  > alcanzabilidad **mirando `routes/` en busca de la clase**, en vez de **seguir
  > las llamadas**. Una clase sin ruta puede estar viva por un `new` desde una que
  > sí la tiene, y una clase con ruta puede tener 400 líneas que nadie alcanza.
  >
  > Lo cruzado con `tools/metodos-sin-camino.py` de `9e`, que parte del método y no
  > del enrutado: **coincidimos en el sitio y no compartimos el supuesto**, que es lo
  > que hace que la coincidencia valga.

## §6 — Lo que se lleva de método

1. **Medir una vez habría publicado un 3,4× que no existe.** Tercera vez esta noche
   con la misma forma, y la tercera vez el número bueno era el aburrido.
2. **Una cota de consultas no ve un cambio de resultado.** El `clone` no lo pide
   ninguna medición de rendimiento: lo pide un test de contrato escrito antes.
3. **Un número que no se entiende no se publica.** El `0` donde tenía que haber `1`
   destapó que el memo sobrevivía a la petición.
4. **Dos medidas del mismo trabajo valen más que una medida con dos comprobaciones.**
   Los 816 se cazaron porque otro test contaba 408, no porque el cronómetro dudara
   de sí mismo.
5. **Y decir en qué rama se contó es parte del número**, o el número se usa mañana
   para justificar otra cosa.
6. **«Este test caza X» es una afirmación y hay que verla caer.** Cuesta tres
   segundos y aquí devolvió más de lo que se afirmaba: el arreglo ingenuo lo cazan
   **tres** tests, dos de ellos anteriores a este trabajo. Sin comprobarlo, este
   documento habría dicho que su test era el único que lo impedía, y eso era falso.
7. **Dos ficheros que son copia uno del otro no son el mismo problema: lo decide
   qué los alcanza, no lo que contienen.** Y el corolario que me costó los dos
   errores simétricos: **la alcanzabilidad no se juzga buscando la clase en
   `routes/`**. Una clase sin ruta puede estar viva por un `new` desde una que sí
   la tiene; una clase con ruta puede tener 400 líneas que nadie alcanza.
8. **Un predicado de conteo es un instrumento y hay que ejercerlo.** El mío estuvo
   mal tres veces —ciego, ancho, y por fin con el discriminador— y las tres daban un
   número creíble. Ahora tiene su propio test con un negativo dentro. Reporté el gemelo
   `CertificadosPersonaController` como «la misma línea que hay que arreglar»
   después de comparar el **código**, y al comprobar el **enrutado** resultó que
   sus tres consultas viven en un subárbol de 400 líneas que no llama nadie.
   Estuvo a punto de entrar en la lista de decisiones de Joseth un arreglo para
   código que no se ejecuta.
