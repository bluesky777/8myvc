# 27 — La nivelación en los informes: reconocimiento antes de A10

> **Sólo lectura, y es a propósito.** A10 —«boletines, constancias y certificados imprimen
> el par»— pasó de la sesión A al carril C-back el 2 sep 2026, y `app/Http/Controllers/Informes/**`
> es desde entonces de este carril. Pero A10 necesita las columnas de A3
> (`notas.nota_original`, `notas_finales.nota_original`, `nivelada_at`, `nivelada_por`), que
> **todavía no están commiteadas** (medido: `grep -rln nota_original app` da cero ficheros en
> `main`). Así que esto es el mapa para escribir A10 el día que estén, y sobre todo **la lista
> con la que Joseth puede decidir lo del puesto** (§3), que hoy tiene planteada en abstracto.
>
> Medido sobre `main` en `e986486` (el árbol del worktree `niv/rubricas`), el 2 sep 2026.
> Las cifras llevan fichero y línea; quien las cite después de un merge de A las vuelve a mirar.

## §1 — Quién imprime notas hoy, y por dónde las saca

`Informes/` tiene 18 controladores y 7.155 líneas. **Diez no leen ni una nota** —Simat,
Observador, ObservadorHorizontal, AcudientesExport, ExcelListadoDocentes, PlanillasAusencias
(usa `Subunidad::deUnidad` para la estructura, sin `n.nota`), ActasEvaluacion (lee
`matriculas.promedio` y `cant_asign_perdidas`, que son columnas guardadas por
`PromovidosController`, no notas), InformesController (dos `COUNT` para estadísticas,
:134 y :142), BolfinalesPreescolar (desempeños, sin número) y `CalcPerdidasDefinitivas`, que
es un ayudante y no un informe—. Los **ocho** que sí, con lo que leen:

| Informe | Ruta · guard | Indicador (`notas.nota`) | Definitiva del periodo (`notas_finales`) | Año (`recuperacion_final`) |
|---|---|---|---|---|
| **Boletín tipo 1 y 5** · `Informes/BoletinesController` | `boletines/detailed-notas/{grupo}`, `/detailed-notas-group/{grupo}`, `/detailed-notas-year/{grupo}` · `boletin.propio` | `Unidad::deAsignaturaCalculada` (:346 → `Unidad.php:169`, **suma** `n.nota×s.porcentaje`) y `Subunidad::deUnidadCalculada` (:349 → `Subunidad.php:92`, `n.nota` fila a fila) | `Grupo::detailed_materias_notafinal` (:282 → `Grupo.php:279`, `n.nota, n.recuperada, n.manual`) y la consulta de :293 (`nota, manual, recuperada` por periodo) | no |
| **Boletín tipo 2** · `Boletines2Controller` | `boletines2/…`, mismas tres · `boletin.propio` | `deAsignaturaCalculada` con `con_desempenio` / `fortaleza_debilidad` (:269, :271) — **por unidad, no por indicador** | igual que el tipo 1 (:206, :217) | no |
| **Boletín tipo 3** (corto, definitivas por periodo) · `Boletines3Controller` | `boletines3/…` · `boletin.propio` | `deAsignaturaCalculada` (:281), sólo la unidad | `Grupo::detailed_materias_notas_finales` (:217 → `Grupo.php:305–316`): **`nota_final_per1..4` sin `recuperada` ni `manual`** | no |
| **Boletín final del año** · `Informes/BolfinalesController` | `bolfinales/detailed-notas-year/{grupo}`, `/detailed-notas-year-group/{grupo}` · `boletin.propio` | sólo las **perdidas** (`n.nota < mínima`, :759 y :819) para la tabla de pendientes | `definitivasMateriasXPeriodo` :483–625, consulta :508 **`SELECT nf.*`** (trae `recuperada`, `manual`) | sí: :359 **`SELECT r.*`**, se imprime aparte, **no entra en el promedio** (:593–623 sólo suma `definitivas`) |
| **Certificado de notas por persona** · `CertificadosPersonaController` | `certificados-persona` (cuerpo `{alumno_id}`) · `boletin.propio` | las perdidas (:501, `n.nota < mínima`) | `definitivasMateriasXPeriodo` :262–428, consulta :309 **`SELECT nf.*`** | sí: :163 **`SELECT r.*`** |
| **Notas actuales del alumno** · `NotasActualesAlumnosController` | `notas-actuales-alumnos/{grupo}` · `boletin.propio` — la pantalla del alumno y del acudiente | `deAsignaturaCalculada` (:187) y `deUnidadCalculada` (:190) | `detailed_materias_notafinal` (:179) | no |
| **Informe de puestos** · `PuestosController` | `puestos/detailed-notas-year`, `/detailed-notas-periodo/{grupo}` · `auth.personal` | las perdidas (`n.nota<:min`, :74, :98, :122, :146, :172) | `avg/sum(nf.nota)` en cinco consultas (:58–:172), con `manual` sólo en la de periodo | no |
| **Notas perdidas** · `NotasPerdidasController` | `notas-perdidas/todos`, `/profesor-grupos`, `/show-profesor/{id}` · `auth.personal` | **sólo** `n.nota < mínima` (:69, :82, :297, :315) | no | no |

Y fuera de `Informes/` pero imprimiendo lo mismo: **`app/Http/Controllers/BolfinalesController.php`**
es una **copia sin ruta** del de `Informes/` (las cuatro rutas `bolfinales/*` de
`routes/api/academico.php:223–226` apuntan a `Informes\BolfinalesController`, :11). Tiene su
propio `ponerPuestos` en :153 al que no llega nadie. Es de la familia «sin ruta y muerto se
borra», y no es de este carril decidirlo; queda apuntado para que A10 **no lo toque creyendo
que es el vivo**.

**`myvc_flutter` no imprime ninguno de estos**: un barrido de `lib/` buscando `boletines`,
`bolfinales`, `certificados-persona`, `puestos` y `notas-actuales-alumnos` como ruta no
encuentra ninguna llamada. Los cuatro clientes del boletín son `myvc_front` y `app2`.

## §2 — Qué le falta a cada uno para imprimir el par, y cuáles NO deben

El par existe en **tres niveles** (plan §3.3): el indicador (`notas.nota` + `nota_original`),
la definitiva del periodo (`notas_finales.nota` + `nota_original`) y el año, que **ya está
aparte** en `recuperacion_final` y ya se imprime como tal en el boletín final y en el
certificado. Lo que A10 añade es sólo los dos primeros.

### 2.1 · Los que SÍ, y con qué cambio

| Informe | Par que imprime | Qué hay que tocar |
|---|---|---|
| **Boletín tipo 1 y 5** | indicador **y** definitiva | `Subunidad::deUnidadCalculada`: añadir `n.nota_original, n.nivelada_at` al `SELECT` (una línea, sirve también a notas actuales); `Grupo::detailed_materias_notafinal` (`Grupo.php:279`): añadir `n.nota_original, n.nivelada_at`; la consulta de `BoletinesController:293`: añadir `nota_original`. **El tipo 5 es el 1 sin números**: hereda el cambio y no pinta nada — es del front decidir si la marca «N» va sin número |
| **Boletín tipo 2** | **sólo definitiva** | las dos consultas de definitiva (:206 y :217), como el tipo 1. El indicador aquí no se imprime: `deAsignaturaCalculada` **suma por unidad** (`Unidad.php:169–170`), y una «nota original de la unidad» sería `SUM(COALESCE(n.nota_original, n.nota)×…)` — un número que **no existió nunca como valoración**. No se inventa |
| **Boletín tipo 3** | **sólo definitiva**, por periodo | `Grupo::detailed_materias_notas_finales` (`Grupo.php:305–316`): añadir `nota_original_per1..4`. **Ojo**: esta consulta hoy no trae ni `recuperada` ni `manual`, así que el tipo 3 **ya imprime la nivelada sin marcarla**. El par le cae encima de un informe que nunca distinguió |
| **Boletín final del año** | definitiva de cada periodo | **nada en la consulta**: `:508` es `SELECT nf.*`, así que `nota_original`, `nivelada_at` y `nivelada_por` **viajan solos** el día que A3 se despliegue (§4). Lo que hay que escribir es el uso: hoy `:574` decide «recuperó» con `si_recupera_materia_recup_indicador` y `manual`; con el par puede decidirlo con `nota_original IS NOT NULL`, que es lo que la marca significa |
| **Notas actuales del alumno** | indicador y definitiva | las mismas dos funciones de modelo que el tipo 1; **cero líneas propias**. Es la pantalla que ve el acudiente, y la que el art. 16 del 1290 tiene en mente |

### 2.2 · Los que NO, y por qué

- **Boletín tipo 4 (preescolar)** — no imprime números. `BolfinalesPreescolarController` no lee
  `n.nota` para pintar sino para el desempeño. **No se toca.**
- **Informe de puestos** — imprime promedios y puestos, no notas. No hay par que pintar; lo
  que le pasa es lo del §3.
- **Notas perdidas** — lista lo que está por debajo de la mínima **hoy**. Un indicador
  nivelado con `topada` queda en 70 y **sale de la lista solo**, que es lo correcto: la
  pantalla contesta «qué falta por nivelar», no «qué se niveló». Poner ahí el par la
  convertiría en otra pantalla.
- **Actas de evaluación** — leen `matriculas.promedio` y `cant_asign_perdidas`, que
  `PromovidosController` guarda al calcular la promoción. No leen notas: heredan lo que
  haya en la matrícula el día que se recalculó. Es una pregunta de A9/A10 aparte: **si
  nivelar el año obliga a recalcular la promoción**, que ya es así hoy con
  `recuperacion_final`.
- **Certificado de estudio** (`CertificadosEstudioController`, fuera de `Informes/`) — no
  lleva notas. Fuera.

### 2.3 · El que es una DECISIÓN: el certificado de notas por persona

`certificados-persona` es el papel más cercano a la **constancia de desempeño** del art. 17
del 1290 —«los resultados de los informes periódicos», por alumno, para el acudiente que lo
pide—, y es el que sale **firmado**. Lee exactamente lo mismo que el boletín final (`nf.*`
en :309, `r.*` en :163), así que técnicamente el par le llega gratis. La pregunta no es
técnica:

1. **Imprimir el par** (`~~55~~ 70` y la fecha) — es literalmente la «comparación de notas
   oficiales del antes y el después» del encargo, y el art. 16 habla de «el estado de la
   evaluación, que incluya las novedades». A favor: es lo que el colegio pidió.
2. **Imprimir la vigente y una nota al pie** («Niveló Matemáticas en el periodo 2») sin el
   número original. A favor: un certificado firmado con tachones se lee como enmendado; el
   dato de la novedad está sin exponer la nota perdida.
3. **Sólo la vigente**, como hoy. A favor: el certificado certifica la valoración final; el
   par vive en el boletín.

**Recomendación de este reconocimiento: la 2 por defecto, con la 1 como interruptor del
año** —al lado de `si_recupera_materia_recup_indicador`, que es su hermano—, porque hay
quince SIEE y no una sola redacción. Pero es de Joseth, y el front de B ya tiene la pantalla
de ajustes del año (B9) donde vivirían los dos.

## §3 — El puesto: dónde se toca, y cuáles tienen el problema del §6.4

### 3.1 · Cómo se calcula, para que lo que sigue se entienda

`Nota::puestoAlumno($promedio, $alumnos)` (`Nota.php:237–247`) es una función **pura**:
arranca en 1 y suma uno por cada promedio del array estrictamente mayor. **No lee ninguna
tabla ni escribe en ninguna.** Desde la fase 6 del 19 la llama un solo sitio,
`BoletinIndependiente::ponerPuestos()` (`Services/BoletinIndependiente.php:466–481`), que
decide quién entra en el recuento y pone `$alumno->puesto` en cada fila. Los informes ya
no la copian: llaman a `ponerPuestos` y el puesto nace **en cada petición**, sobre el
promedio calculado **en esa misma petición** desde `notas_finales`.

O sea que el enunciado del §6.4 de las tareas es exacto y **no es de la nivelación**: **hoy,
sin nivelaciones, cualquier corrección de una definitiva ya mueve el puesto de todo el
grupo en la siguiente impresión.** Lo que la nivelación cambia es la escala: una semana de
nivelaciones es una semana de correcciones sistemáticas y a la vez, justo después de
entregar los boletines.

### 3.2 · Los sitios, con fichero y línea

| # | Dónde se pone el puesto | Promedio contra el que se cuenta | Papel o pantalla | ¿Tiene el problema? |
|---|---|---|---|---|
| 1 | `Informes/BoletinesController.php:256` — `ponerPuestos($alumnos, [periodo del token])` | definitivas de **ese periodo**, `allNotasAlumno` :379–381 | **boletín tipo 1 y 5**, papel | **SÍ.** Reimprimir el boletín del periodo 2 en la semana de nivelaciones da otro puesto a todo el grupo |
| 2 | `Informes/Boletines2Controller.php:185` | ídem, :305–307 | **boletín tipo 2**, papel | **SÍ**, igual |
| 3 | `Informes/Boletines3Controller.php:190` | `prom_year` = media de las definitivas **de todos los periodos hasta el pedido**, :228–237 | **boletín tipo 3**, papel | **SÍ, y más**: nivelar el periodo 1 en abril mueve el puesto del boletín del periodo 3 |
| 4 | `Informes/BolfinalesController.php:451` — sobre `$year->periodos` | media de las definitivas del año, :593–623 (**sin** `recuperacion_final`, que se lee aparte en :359) | **boletín final**, papel | **SÍ**, y es el que se reimprime más: se pide en enero para la matrícula del año siguiente |
| 5 | `Informes/CertificadosPersonaController.php:233` | ídem que el 4, :399–428 | **certificado firmado** | **SÍ, y es el grave**: dos certificados de la misma persona, expedidos en marzo y en julio, con puestos distintos y ambos firmados |
| 6 | `Informes/PuestosController.php:213` y `:328` — aquí el backend **no pone el puesto**: filtra con `losQueCuentanParaElPuesto` y **el front lo cuenta** con el filtro `puestoAlumno` sobre el array (docblock :40–56) | `avg(nf.nota)` :58–172 | **informe de puestos**, papel | **SÍ**, pero es el informe cuyo nombre es «puestos a hoy»; el problema aquí es de rótulo, no de dato |
| 7 | `EditnotaController.php:236` | definitivas del periodo del token | pantalla del editor de definitivas | no es papel: **debe** moverse en vivo |
| 8 | `PromovidosController.php:157` | año | pantalla de promoción, y **escribe** `matriculas.promedio` | no es papel; y que la promoción cambie tras nivelar el año es **el objetivo** |
| 9 | `app/Http/Controllers/BolfinalesController.php:153` | — | **sin ruta** (§1) | no llega nadie |

Cinco papeles con el problema (1–5), uno con el problema de rótulo (6), dos pantallas donde
es lo deseado (7–8) y uno muerto (9). **Y los cinco lo tienen también para los alumnos que
no nivelaron nada**, porque `puestoAlumno` cuenta cuántos están por encima: si el que iba
séptimo niveló y subió a cuarto, el cuarto, quinto y sexto bajan uno sin haber tocado una
nota.

Lo que hoy modula el problema, sin resolverlo: `years.mostrar_puesto_boletin` y
`years.puestos_alfabeticamente` viajan en el contexto (`ContextoDeUsuario.php:113`) y los
lee **el front**: el backend calcula el puesto siempre. Un colegio con el interruptor a 0
no imprime el puesto y no tiene el problema en el papel; sigue teniéndolo en el informe de
puestos.

### 3.3 · Las dos salidas, con su coste, para que Joseth elija con la lista delante

**A · Congelar el puesto al cerrar el periodo.** Una tabla `puestos (alumno_id, grupo_id,
periodo_id, promedio, puesto, calculado_at)` —o el año entero con `periodo_id NULL`— escrita
**una vez** por un comando o al poner `profes_pueden_editar_notas = 0`, y leída por los cinco
sitios 1–5 cuando exista la fila, con `ponerPuestos` como respaldo cuando no. Reimprimir da
**el mismo puesto que el día de la entrega**, que es lo que el acudiente espera de un papel
que ya tiene en casa. Coste: una migración, un escritor con su decisión de *cuándo*, cinco
lectores y un test que fije que congelado y calculado coinciden el día del cierre. Riesgo:
un colegio que reabre el periodo, corrige y vuelve a cerrar tiene **dos verdades** salvo que
el cierre reescriba la fila — y entonces hay que decidir si reescribe.

**B · Aceptar que se recalcula, y decirlo en el papel.** Cero líneas de backend. El front
pone «Puesto a fecha de impresión» en los cinco, y el informe de puestos ya lo dice con su
nombre. Coste: una etiqueta. Riesgo: el acudiente con el boletín de marzo y el de julio
sigue viendo dos números, sólo que ahora con fecha.

**Este reconocimiento no elige.** Lo que sí dice es que la A **no puede ir en A10 sin
decidirse antes**, porque los cinco lectores son exactamente los ficheros que A10 va a
tocar, y tocarlos dos veces —una para el par y otra para el puesto congelado— es el diff
que nadie va a querer revisar.

## §4 — Lo que le va a pasar a estos informes el día que A3 se despliegue, sin que A10 exista

Es el hallazgo que no estaba en la pregunta y hay que decirlo antes de que muerda:

- **`Informes/BolfinalesController.php:508`** y **`CertificadosPersonaController.php:309`**
  leen `SELECT nf.*` de `notas_finales`. Las tres columnas de A3 (`nota_original`,
  `nivelada_at`, `nivelada_por`) **aparecerán solas en la respuesta** del boletín final y del
  certificado en cuanto la migración corra, con el código de hoy. Es la familia de
  `notas/detailed` que el 24 ago cazó `8myvc-9e` (comentario en `NotasController:52–71`).
  Si esos dos tienen snapshot de forma, **se ponen rojos con A3 y no con A10**; si no lo
  tienen, el campo viaja sin que nadie lo haya decidido.
- **`Nota::alumnoPeriodoDetalle`** (`Nota.php:367` y `:385`) hace `SELECT * FROM notas`, así
  que las columnas de A3 llegan solas **a la ficha del alumno y a promovidos**, que son quien
  la llama (`NotasController:435` en `putAlumnoPeriodoGrupo`, y `Nota.php:272`).
  **NO a `notas/detailed`**, que ya nombraba sus columnas una a una: **A7 sí tiene que
  añadirlas explícitamente allí**, y si no lo hace, la planilla de B se queda sin los seis
  campos sin que falle nada.

  > La primera versión de este documento decía lo contrario —«A7 las recibe gratis»— y lo
  > corrigió A el 2 sep 2026 leyendo el controlador. Se deja escrito el error porque es la
  > **segunda** vez en la misma noche que una afirmación plausible sobre `detailed` manda a
  > alguien por el camino equivocado: la otra fue un comentario de `app.routes.ts` que dio
  > por hecho que ese endpoint «filtra por profesor y con 0 responde 200 con cero alumnos».
  > Las dos veces la afirmación era razonable y las dos veces bastaba abrir el método.
  > **De este endpoint no se afirma nada sin leer `putDetailed`.**

- **`NotasController:103`** hace `SELECT * FROM subunidades` y cuelga las filas de
  `$unidad->subunidades`, que viaja al cliente. Es la misma fuga por otra tabla, y la
  disparó **la columna `subunidades.rubrica_id` del carril C**: `NotasTest::la forma de la
  rejilla del profesor` se pone rojo con `'rubrica_id' => 'null'` de más. **El fichero es de
  la sesión A**, así que se pide y no se edita. Lo mismo hacen `AsignaturasController:73`,
  `UnidadesController:50` y `:516` y `ChangeAskedController:1261`, que habría que mirar uno
  a uno antes de integrar rúbricas.
- **`:359` y `:163`** hacen `SELECT r.*` de `recuperacion_final`: lo mismo con los metadatos
  de acta de A9.
- `Asignatura.php:98` y `:145`, `Unidad.php:309`: `SELECT * FROM notas` en los cálculos de
  definitivas. No devuelven al cliente, pero A4 los va a ver pasar.

**Para A:** esto vale por una línea en su lista de A3 — «correr los snapshots de boletín
final y certificado después de migrar, y leer qué campos nuevos aparecen». **Para A10:** el
trabajo en el boletín final y el certificado **no es la consulta, es el uso**.

### 4.1 · Hecho: las cuatro consultas nombran sus columnas

El 2 sep 2026, y **antes que A10 a propósito**: el momento del riesgo no es cuando se
escriba la impresión del par, es **cuando corra la migración en ese colegio**, con el código
de hoy. Entre A3 desplegada y A10 escrita hay semanas, y en esa ventana el boletín final
mandaba tres columnas que nadie decidió enseñar.

| Consulta | Antes | Ahora |
|---|---|---|
| `Informes/BolfinalesController` · definitivas del periodo | `SELECT nf.*` | las **once** de `notas_finales`, nombradas |
| `Informes/BolfinalesController` · recuperaciones del año | `SELECT r.*` | las **ocho** de `recuperacion_final` |
| `CertificadosPersonaController` · las dos mismas | ídem | ídem |

**La respuesta no cambia ni un campo**, y hay una razón concreta: con `nf.*` la columna
`nota` viajaba **dos veces** —la cruda y la casteada a DOUBLE— y en PDO gana la última, que
es la casteada. La lista nombrada no la repite y el valor es el mismo.

Lo fija `tests/Contrato/BoletinFinalSinAsteriscoTest`, que mira **las claves que llegan al
cliente** y no el SQL: un test que buscara el asterisco en el fichero se pondría rojo por el
comentario que lo cita al lado. Dos cosas que ese test tuvo que resolver y quedan escritas
porque se repetirán:

- **la recuperación se inserta.** `recuperacion_final` está vacía en el seed y los snapshots
  del boletín final guardan `"recuperaciones": []`, así que la mitad del asterisco **no la
  miraba nadie**: un test que se fiara del seed daba verde con el asterisco puesto;
- **los periodos de relleno se saltan.** `:568` empuja filas ficticias
  (`periodo_id: -1`, sin `id`) para cuadrar la tabla del papel; exigirles las once columnas
  sería rojo por una fila que no viene de la tabla.

`CertificadosPersonaController` **se cambió igual y no tiene test**, y es deliberado: su
`detailedNotasGrupo` no lo alcanza nadie (05 §211 y §218), así que un test que lo diera por
vivo mentiría sobre qué llega a un cliente.

## §5 — El plan de A10: hecho lo que no espera a nadie, y lo que falta espera a Joseth

En el orden en que se puede desplegar sin que un colegio imprima mal (tareas §7).

### 5.1 · HECHO el 2 sep 2026 — los tres que no dependen de ninguna decisión

| Qué | Dónde | Cómo quedó |
|---|---|---|
| El par del **indicador** | `Subunidad::deUnidadCalculada` — `nota_original`, `nota_nivelacion`, `nivelada_at`, `nivelacion_obs` | de ahí cuelgan el **tipo 1 y 5** y las **notas actuales del alumno** |
| El par de la **definitiva** | `Grupo::detailed_materias_notafinal` — `nota_original_asignatura`, `nivelada_at_asignatura` | la comparten el tipo 1/5, el **tipo 2** y las notas actuales |
| La tabla «Periodo 1 · 2 · 3 · 4» del papel | `Informes/BoletinesController:293` | `nota_original` y `nivelada_at` por periodo |
| El **boletín final** | `Informes/BolfinalesController:508` | las once columnas pasan a **catorce**: era el sitio que la §3.4 del [22](22-nivelaciones.md) tenía como «congelado hasta A10» |
| La tabla de periodos del **tipo 2** | `Boletines2Controller:217` | la misma línea del tipo 1; su indicador **no** se toca (suma por unidad) |
| `GET notas/alumno` | por la consulta compartida de `Grupo` | gana el par de la definitiva **a sabiendas** — ver abajo |

Tres decisiones que van con eso:

- **`nota_nivelacion` viaja además de `nota_original`.** Con la regla `topada` las dos son
  distintas de la vigente —sacó 90 y le queda 70—, y un boletín que sólo enseñara
  `~~55~~ 70` escondería lo que el alumno hizo, que es justo lo que el acudiente viene a ver.
- **Los alias de la definitiva llevan el sufijo `_asignatura`**, como el `nota_asignatura`
  que ya existía. En las tres respuestas conviven el par del indicador y el de la
  definitiva: dos claves `nota_original` significando niveles distintos es la forma de que
  el papel imprima la del nivel equivocado.
- **Las claves van siempre, con `null` cuando no hay nivelación** (22 §3.1). Una clave que
  sólo aparece con dato obliga al front a distinguir «vacío» de «no vino».

> **`GET notas/alumno` gana el par, y se decidió — no se coló.** La §3.4 del
> [22](22-nivelaciones.md) marcaba esa ruta como **congelada**, y come de la misma consulta
> compartida. Se aceptó (Joseth y el coordinador, 2 sep 2026) por tres razones: **Flutter la
> llama y no se rompe** —comprobado leyendo `AsignaturaNotaModel.fromJson`, que lee clave a
> clave e ignora las que no conoce, no supuesto—; es **coherente con el certificado firmado**,
> porque si el papel firmado lleva la novedad al pie, esconderla en la pantalla del propio
> alumno no se sostiene; y acotarlo habría costado **un parámetro en un método con cuatro
> llamantes**, complejidad permanente a cambio de literalismo.

**Ocho instantáneas regeneradas y leídas**, y el diff es la prueba de que la migración es
aditiva: **todas claves nuevas con valor `null`**, ninguna quitada y ninguna
cambiada. `nota` sigue siendo la vigente (plan §3.2), así que el front viejo y Flutter
imprimen exactamente lo de antes. Lo fija `tests/Contrato/BoletinImprimeElParTest`, con la
nota nivelada **y con una sin nivelar**.

> **Y una trampa del escenario, que costó cuatro rojos y se repetirá.** La primera versión
> del test elegía «un periodo del año» y los tres informes pintan **`$user->periodo_id`**:
> la nota nivelada no salía en la respuesta y el test habría dado verde sin mirar el par si
> el aserto no hubiera exigido población. El periodo se saca **del mismo usuario que eligió
> `tokenDelPersonalDe`**.

### 5.2 · La decisión del certificado firmado, contestada — y no era backend

**Joseth eligió la opción 2** (2 sep 2026): el certificado imprime **la nota vigente** y al
pie la novedad, «niveló 48 → 70 en el periodo 2». Sin el par tachado dentro de la tabla y
**sin interruptor del año**: no lo pidió, y un interruptor que nadie ha pedido es una opción
más que mantener.

Y al ir a escribirla apareció lo que la §2.3 no había mirado: **ese papel no lo produce
`CertificadosPersonaController`**. Su `detailedNotasGrupo` sigue muerto (05 §211 y §218); el
certificado lo **arma el front** pidiendo `bolfinales/detailed-notas-year/{grupo}` —lo dice
su propia pantalla: *«por cada una hay que pedir el certificado de verdad, la MISMA llamada
que usa `panel.informes.certificados_estudio`»*—.

**Así que la opción 2 ya está servida y el backend no tiene nada que hacer.** Esa respuesta
trae desde la §5.1 `nota_original`, `nivelada_at` y `nivelada_por` por definitiva, más
`periodo`, que es exactamente lo que hace falta para escribir la frase. La nota al pie es
trabajo del front, en `informes/certificado-estudio/`.

> **Y por eso se comprobó antes de escribir.** Implementar la opción 2 «en el certificado»
> habría añadido código a un método que no alcanza nadie, en un fichero que ya tiene **445
> líneas inalcanzables**. Es literalmente el error que el 05 guarda de este mismo sitio:
> *«con `CertificadosPersonaController` se dijo "hay que arreglarlo" y estaba muerto»*.

### 5.3 · Lo que falta, y por qué no se ha escrito

1. **El tipo 3.** Es el único que sigue sin el par de la definitiva, y **no es la misma
   línea que el tipo 2**: `Grupo::detailed_materias_notas_finales` no tiene una consulta,
   tiene **cuatro** —una por `num_periodo` 1..4— y cada una lleva dentro subconsultas
   `nota_final_per1..4` con su `nf_id_N` y su `nf_updated_atN`. Son unas diez inserciones y
   tocan un `@rownum` que ya es frágil. **Se paró aquí a propósito**: el tipo 2 se cerró
   porque era una línea; éste es una tarea con su propio riesgo.
2. **El criterio de «recuperó»** en `BolfinalesController:574` —y su gemelo del certificado,
   `:375`—: hoy se deduce de `si_recupera_materia_recup_indicador` **y** de `manual`, y con
   el par podría ser `nota_original IS NOT NULL`, que es lo que la marca significa. **No se
   ha tocado porque no es aditivo**: cambia lo que imprimen los quince colegios hoy, no sólo
   lo que se añade.
3. **El puesto** (§3.3), que sigue esperando a Joseth. Si elige congelar, va **antes** que
   todo lo anterior y son los mismos cinco ficheros: hacerlo después es tocarlos dos veces.
4. **Nada en el tipo 4, notas perdidas, actas ni certificado de estudio**, y el
   `BolfinalesController` de la raíz **no se toca**: o se borra en su propio lote o se deja.

