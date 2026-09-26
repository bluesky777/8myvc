# 48 — Los informes pesados: certificados y boletines

24 sep 2026. Encargo de Joseth: analizar y rehacer los endpoints que tardan en
cargar, empezando por certificados y boletines. **Desplegado en LAL el 25 sep
(`086b5a8`); lo del 26 sep está en §Los boletines, uno por uno.** Lo del 24:

| | Commit | Antes → después (consultas) |
|---|---|---|
| P1 — faltas contadas en la celda | 8myvc `c668b44` | boletín final simon: 24–32 s → 0,6–0,8 s |
| P2a — certificado de todos los años, de uno en uno | front `ae0c6f1d` | 8 procesos a la vez → 1 |
| P3a — reparto recordado mientras se arma | 8myvc `1c4209c` | boletín de periodo 9.172 → 5.145 |
| P2b — `sin_puesto` / año sin puesto | 8myvc `2bd1741`, front `7bf20c4d` | certificado de un alumno 922 → 39 |
| P3b — subunidades no repetidas + precarga por grupo | 8myvc `279bd84`, `8e9bde0` | boletín de periodo 5.145 → 1.121 |
| P2c — con puesto, al resto sólo el promedio | 8myvc `584f69d` | boletín final de una hoja 855 → 43 |
| notas-actuales — faltas, frases y perdidas por grupo | 8myvc `01ff4de` | grupo de 38: 30.222 → 4.021 |

Todas con el JSON idéntico por sha1 antes y después (P2b: idéntico salvo `puesto`,
que va a `null` en una hoja que no lo imprime). **De paso:** `notas-actuales-alumnos`
hacía 30.222 consultas y 11 s en un grupo de 38; ahora 4.021 y ~2,4 s. Queda P4, y
los siguientes candidatos están en §El barrido.

Por qué importa: los 16 colegios viven en 2 cuentas de cPanel con 50 Entry
Processes cada una (`02-plan-rendimiento.md:726-745`). Lo que tumba la cuenta no
es el número de funciones sino **los segundos que una petición retiene un proceso**.

## Lo medido

Contra copias propias del docker, migradas: `rend_simon` (copia de
`simonbolivar`: 1,17 M notas, **52.173 ausencias**) y `rend_quibdo` (copia de
`quibdo_24sep_1104`: 1,47 M notas, 3.865 ausencias). Una pasada, máquina con carga
~3,4; los milisegundos orientan, las consultas y las huellas no dependen de la
máquina.

| Endpoint (grupo, alumnos) | Base | Consultas | ms | de ellos SQL |
|---|---|---:|---:|---:|
| `bolfinales/detailed-notas-year-group/105` (Once, 38) | simon | 1.007 | 32.103 | 99 % |
| `bolfinales/detailed-notas-year-group/102` (Octavo, 43) | simon | 922 | 24.064 | 99 % |
| `bolfinales/detailed-notas-year/102` **con UN alumno** | simon | 922 | 27.401 | 99 % |
| `bolfinales/detailed-notas-year-group/223` (38) | quibdo | 855 | 2.017 | 95 % |
| `boletines/detailed-notas-group/223` (periodo, formato 1) | quibdo | **9.172** | 2.954 | 91 % |
| `boletines2/detailed-notas-group/223` | quibdo | 3.891 | 1.884 | 85 % |
| `boletines-competencias/detailed-notas-group/223` | quibdo | 1.074 | 573 | — |
| `boletines3/detailed-notas-group/223` | quibdo | 165 | 136 | — |
| `boletines/detailed-notas-group/102` | simon | 2.809 | 840 | 85 % |

### Tres cosas que corrigen lo que se creía

1. **`certificado-grupo` (3.820 consultas, 11 s) no es el problema**: da 500 en
   toda llamada porque su vista no existe, y ninguna pantalla lo usa
   (`COORDINACION-NOCHE.md`, «El gemelo caro que NO hay que optimizar»). Los
   certificados de app2 van por `bolfinales/detailed-notas-year`.
2. **El docker local no se queda corto en notas**: `simonbolivar` tiene 1,17 M,
   igual que producción; la de 90.000 es `micolev1_la_hermosa`, que es la que
   tiene hoy el `.env`. Lo que decide el tiempo **no es el tamaño de `notas`, es
   el de `ausencias`**: con los mismos grupos, quibdo (3,9 mil ausencias) tarda 2 s
   y simonbolivar (52 mil) 24–32 s.
3. **Pedir un alumno cuesta lo mismo que el grupo entero** (922 consultas, 27 s en
   simon; la respuesta sí baja de 1,7 MB a 46 KB). El bucle calcula a todos y
   filtra al final, porque el puesto necesita el promedio de todos.

### La causa, una sola consulta

**El 98,5 % del tiempo del boletín final es una forma de consulta**:
`BolfinalesController::definitivasMateriasXPeriodo` (Informes, línea ~685), que se
lanza una vez por alumno × asignatura (456 veces en un grupo de 38) y, para contar
las faltas de UNA celda, hace dos subconsultas derivadas que agrupan **la tabla
`ausencias` entera** (`GROUP BY alumno, periodo, asignatura` sin filtro). Cada
ejecución recorre las 52 mil filas dos veces: 84 ms × 456 = 24 s.

## Las propuestas, por orden de precio

### P1 — Contar las faltas de la celda y no de todo el colegio (hecho en prototipo)

Las dos subconsultas derivadas pasan a subconsultas correlacionadas con
`NULLIF(COUNT(...), 0)`, que devuelven exactamente lo mismo (el `LEFT JOIN` contra
grupos agrupados nunca daba 0, daba `NULL` o ≥ 1). Usan los índices que ya existen
en `ausencias` (alumno, asignatura, periodo): **sin migración, sin índice nuevo**.

Resultado, antes → después, **con el JSON idéntico byte a byte (sha1) en los 17
casos**: 9 grupos de simon (años 2021, 2025 y 2026, de Transición a Once), 5 de
quibdo (2025 y 2026), el certificado de un alumno y el de hasta un periodo.

| Grupo | antes | después |
|---|---:|---:|
| simon 105 (Once) | 32.103 ms | 814 ms |
| simon 65 (Décimo 2021) | 31.277 ms | 846 ms |
| simon 102 (Octavo) | 24.836 ms | 611 ms |
| simon 55 (Transición 2021) | 6.303 ms | 315 ms |
| quibdo 223 | 2.128 ms | 528 ms |

La misma consulta está copiada en `PromovidosController::definitivasMateriasXPeriodo`
(ruta viva: `promovidos/calcular-grupo`), en el `BolfinalesController` viejo (sólo
lo llama `CertificadosEstudioController`, que da 500) y en
`CertificadosPersonaController` (sin camino). **Se propone aplicarla en las dos
vivas** y dejar las muertas.

Riesgo: bajo. Toca una sola sentencia por fichero; el candado es la huella.
Despliegue: los 16 colegios (es `app/`).

### P2 — El certificado de todos los años: de N peticiones en paralelo a una en fila

`certificados-alumno` lanza un `bolfinales/detailed-notas-year` por cada año
cursado **a la vez** (`forkJoin` sin límite): un alumno de 8 años son **8 procesos
de golpe, ~27 s cada uno en simon**, y cada uno calcula el grupo entero de ese año
para quedarse con una hoja. Dos secretarias imprimiendo son 16 de los 50.

- **P2a (front, barato):** encadenarlas (`concatMap`) o dejar 2 a la vez. Con P1,
  8 × ~0,7 s en fila ≈ 6 s y **un solo proceso**. Sólo despliegue de `myvc_dist`.
- **P2b (tu idea, backend):** si el colegio tiene `mostrar_puesto_boletin = 0`, al
  pedir alumnos concretos calcular sólo esos: de 38 a 1 alumno, ~×38 menos
  trabajo. Ojo al alcance: en las copias del docker sólo `la_hermosa` lo tiene en 0
  (quibdo, caz y simon en 1), y el interruptor es del **año actual**; el
  certificado lee años pasados. Hay que decidir de qué año se lee (el del grupo
  pedido, seguramente) y comprobar en el censo cuántos colegios lo tienen apagado.
- **P2c (backend, con puesto encendido):** el puesto sólo necesita el promedio de
  cada alumno. Calcular a fondo al alumno pedido y, para los demás, sólo su
  promedio en una consulta agregada por grupo. Es la que más gana y **la más
  cara de probar**: el promedio pasa por recuperaciones, nivelaciones y la regla
  de quién cuenta (decisión 6 de `BoletinIndependiente::ponerPuestos`). Sólo si
  tras P1+P2a sigue haciendo falta.

### P3 — Boletín de periodo (formato 1): 9.172 consultas

`boletines/detailed-notas-group` no es lento por una consulta mala sino por
volumen: `SELECT reparto_subunidades FROM years WHERE id = ?` se lanza **4.028
veces** en un grupo (un valor que no cambia en toda la petición) y las notas por
subunidad se piden por alumno × asignatura (2.660 + 456 + 456…). Propuesta:

- **P3a:** memorizar `reparto_subunidades` por petición. Quita el 44 % de las
  consultas, riesgo casi nulo.
- **P3b:** traer notas, subunidades, ausencias y frases **por grupo** (una consulta
  cada una con `WHERE alumno_id IN (...)`) y repartir en PHP, como ya se hizo con
  la planilla de notas (`20-pantalla-de-notas.md`, 717 → 220). Mismo candado:
  huella del JSON por grupo y periodo.

`boletines2` (3.891) comparte el esquema y entra en el mismo trabajo;
`boletines3` y competencias ya son baratos.

### P4 — Encender el registro de consultas lentas en un colegio

Todo lo de arriba se midió en el docker. `CONSULTAS_LENTAS_MS` existe
(`app/Support/ConsultasLentas.php`) y está apagado en todos. Encenderlo una semana
en el colegio con más ausencias diría si hay otra consulta como la de P1 que el
docker no ve. Es un cambio de `.env`, lo decides tú.

## El barrido: el resto de informes de app2

24 sep 2026, `tools/medir-un-informe.php` contra `rend_quibdo` (grupo 223, periodo 3,
profesor 66 con 20 asignaturas), una pasada por endpoint, sólo los que leen. Los
de arriba ya no están.

| Endpoint | Pantalla | Consultas | ms | Qué lo multiplica |
|---|---|---:|---:|---|
| `GET planillas/show-profesor/66` | `informes/planillas/planillas.ts` | **27.770** | 15.304 | una a `notas` por subunidad × alumno |
| `GET planillas-ausencias/show-profesor/66` | `datos/planillas-ausencias.ts` | 27.770 | 12.012 | la misma respuesta (mismo sha1): un arreglo cubre las dos |
| `PUT notas-perdidas/todos` | `notas-perdidas-todos.ts` | **14.192** | 14.149 | alumnos con sus notas, una por asignatura; 19 MB de respuesta |
| `GET planillas/ver-ausencias` | `ver-ausencias.ts` | 2.733 | 1.712 | una por asignatura |
| `PUT notas-perdidas/profesor-grupos` | `notas-perdidas-profesor.ts` | 2.255 | 1.582 | la de `todos`, para un profesor |
| `GET observador/vertical-todos` | observador | 570 | 374 | acudientes uno por alumno; 11 MB |
| `PUT acudientes/planillas-ausencias` | `planilla-acudientes.ts` | 569 | 310 | acudientes uno por alumno |
| `PUT puestos/detailed-notas-year` | puestos del año | 202 | 1.217 | pocas, pero una forma se lleva 1,1 s |

El resto (actas, listados, SIMAT, cumpleaños, observador de un grupo…) está por
debajo de 210 consultas y 200 ms. **Los dos primeros son los candidatos**: un
profesor que abre su planilla ocupa un proceso 12–15 s, más que el boletín final
de antes de P1 en quibdo. No se midieron `comportamiento/observador-*`, que
escriben filas al abrirse.

## Preparar el despliegue

**Nada de esto lleva migraciones**, y los dos lados se pueden desplegar en
cualquier orden: el front manda `sin_puesto`, que un backend viejo ignora, y un
backend nuevo sin el front nuevo calcula el puesto como siempre.

- **Backend, los 16 colegios:** `c668b44` → `01ff4de` (P1, P3a, P2b, P3b, P2c y
  notas-actuales). `tools/desplegar.sh` sin `--ejecutar` enseña el plan colegio a
  colegio; debe decir *cero migraciones*.
- **Front, `myvc_dist`:** el build de app2 con `ae0c6f1d` (años de uno en uno) y
  `7bf20c4d` (`sin_puesto`).

**Cómo saber en un colegio que bajó**, sin el medidor —que sólo corre en el
docker—: abrir el boletín final de un grupo grande antes y después, y mirar en
las herramientas de red del navegador el tiempo de `detailed-notas-year-group`.
En el colegio con más ausencias (simonbolivar en las copias) es donde se nota:
de 24–32 s a menos de 1 s. Si se enciende P4 en ese colegio, el registro de
consultas lentas debería dejar de mostrar la de `notas_finales` con `ausencias`.

## Los boletines, uno por uno — 26 sep 2026

Encargo de Joseth: **no medir en producción cuál tarda** (ya se sabe: los boletines),
sino repasar todos sus endpoints y hacerlos mucho más rápidos. Rama `perf/boletines`,
worktree `.worktrees/boletines`. Mismo método que arriba (§Cómo se midió): JSON
comparado por sha1 contra `main` antes de cada commit.

Estado al cerrar el 26 sep (rama `perf/boletines`, **sin empujar ni desplegar**).
«Antes» es `b4bfe40` (main + P5); P5 aparte, abajo. ms: una pasada antes, la mejor de
dos después, máquina sin otros agentes. Quibdo grupo 223 periodo 3, simon grupo 102.

| Endpoint | Base | Consultas | ms | Respuesta | Estado |
|---|---|---:|---:|---:|---|
| `boletines/detailed-notas-group` (formato 1) | quibdo | 1.577 → **175** | 1.827 → 219 | 6,6 MB | hecho |
| `boletines/detailed-notas-group` | simon | 1.094 → **194** | 1.088 → 147 | 1,0 MB | hecho |
| `boletines/detailed-notas` (un alumno) | quibdo | 1.728 → **178** | 1.846 → 164 | 180 KB | hecho |
| `boletines2/detailed-notas-group` | quibdo | 3.436 → **215** | 2.243 → 233 | 6,0 MB | hecho |
| `boletines3/detailed-notas-group` | quibdo | 3.433 → **271** | 48.673 → 362 | 2,2 MB | hecho (P5 + P6) |
| `boletines3/detailed-notas-group` | simon | 2.464 → **321** | 31.343 → 224 | 0,6 MB | hecho (P5 + P6) |
| `boletines-competencias/detailed-notas-group` | quibdo | 1.074 → 1.000 | 441 → 403 | 365 KB | casi nada: ver pendientes |
| `bolfinales/detailed-notas-year-group` | quibdo | 855 → **63** | 492 → 142 | 2,3 MB | hecho |
| `bolfinales/detailed-notas-year-group` | simon | 922 → **111** | 507 → 174 | 1,7 MB | hecho |
| `bolfinales-preescolar/detailed-notas-year-group` (208) | quibdo | 663 → **20** | 209 → 61 | 111 KB | hecho |
| `puestos/detailed-notas-year` | quibdo | 240 → **17** | 1.693 → 74 | 90 KB | hecho |
| `puestos/detailed-notas-periodo` | quibdo | 125 → **53** | 539 → 72 | 118 KB | hecho |

Sobre 69 casos (los de arriba más periodos 1/2/4, hojas sueltas, años pasados, grupos
con faltas, frases, recuperaciones, independientes y celdas duplicadas; lista en el
scratchpad de la sesión): **105.816 → 20.672 consultas, 65,7 s → 17,4 s sumados**, y
**68 idénticos por sha1** a `b4bfe40`. El que no: ver P6, «el duplicado».
`--filter "Bolet|Bolfinal|Puesto|Certificad|NotasActuales|Informe|Perdidas|Unidad|Subunidad"`:
433 pruebas en verde.

**El 124 ms / 165 consultas que decía la tabla de arriba para `boletines3` ya no es
verdad**: hoy tarda 48 s. No es una regresión de esta semana que se haya buscado; es
que aquella medida fue en otro periodo o antes de que la nivelación (27 §5.3)
añadiera las subconsultas. Con el grupo entero en 48 s, un colegio que imprima el
formato 3 retiene un proceso casi un minuto.

### P5 — `boletines3`: el alumno dentro de las derivadas (hecho, `49efc49`)

`Grupo::detailed_materias_notas_finales` (sólo la usa `Boletines3Controller`) tiene
hasta diez subconsultas `select distinct … from notas_finales nf order by nf.id desc`
que se filtraban por alumno **en el `ON`**, fuera de la derivada: MySQL materializaba
`notas_finales` entera (199 mil filas en quibdo) por periodo y por alumno. Se mete
`where nf.alumno_id = <entero>` dentro de cada una. 48,7 s → 1,8 s en quibdo p3;
16,9 → 1,4 s (p1), 32,7 → 1,6 s (p2), 62,7 → 2,0 s (p4); simon 31,3 → 0,9 s.

JSON idéntico en quibdo p1–p3. **En p4 y en simon cambia algo, y es a mejor**: dos
asignaturas del mismo área con `orden` nulo (Inglés y Lengua en simon) salían en el
orden en que el plan las leía, **distinto de un alumno a otro en el mismo grupo**; ahora
desempatan por `a.id` y salen igual en todos. El `indice` (`@rownum`) también cambia;
no lo pinta ninguna pantalla de boletines. 190 pruebas con `--filter Boletin` en verde.

### P6 — Todo lo demás de los boletines, por grupo (hecho, 26 sep)

Tres agentes en paralelo, una rama cada uno, juntadas en `perf/boletines` (`fe6fa82`):

- **Formatos 1 y 2** (`perf/boletines-12`): las pérdidas del año —la forma
  `…definitiva_year, cant_perdidas_1…` de ~800 ms— salen en una consulta para el grupo
  (`CalcPerdidasDefinitivas::delGrupo`); unidades, subunidades y escalas una vez por
  grupo (`Support/LasUnidadesDelGrupo`, parámetro opcional `$delGrupo` en `Unidad`).
- **Formato 3 y competencias** (`perf/boletines-3c`): reparto memorizado como P3a,
  faltas y frases con `LoDelGrupoDeUnaVez`, pérdidas con `delGrupo`, y
  `Unidad::recordandoElGrupo` (una memoria estática que sólo enciende `boletines3`).
- **Bolfinales, preescolar y puestos** (`perf/boletines-fp`): definitivas, materias,
  comportamiento, frases y recuperaciones una vez por grupo; preescolar filtra las
  hojas antes de calcular; puestos, las definitivas del año del grupo en una consulta.
  Aparte del sha1, comparado alumno por alumno contra las consultas viejas en 282
  grupos (comportamiento y recuperaciones) y en los 68 grupos de 2025-2026 (puestos).

**El duplicado.** Con dos filas de `notas_finales` para la misma celda y periodo
(simon 103, alumno 547, asignatura 1293, periodo 2, las dos con nota 0), ninguna
consulta vieja ordenaba y cada informe servía la que su plan leía: **los boletines la
más nueva (7249491) y `notas-actuales` la vieja (7248184)**. `delGrupo` ordena por id
descendente (`bfb426e`): ahora todos dan la nueva, y `notas-actuales` de ese grupo
cambia ese `nf_id_2`. No hay orden que reproduzca a los dos viejos a la vez.

**Empates de orden que no se tocaron**: en puestos, dos asignaturas con
`(ar.orden, m.orden, a.orden)` iguales salen en el orden del plan, **igual que en la
base** (dos pasadas de la base dieron sha1 distintos). Un desempate escrito acierta en
simon y da la vuelta en quibdo (grupo 168).

### Lo que queda en los boletines, con su precio

- **Competencias**: 760 de sus 1.000 consultas son
  `DefinitivasDeAsignatura::ponerAlDiaUnInforme` reparando 380 definitivas. En la
  medida se repiten porque la transacción se deshace; en producción es una vez y
  luego no. Es escritura: no se tocó.
- **`ponerAlDiaLasDefinitivas`** en el boletín de un alumno (~150 consultas): escribe,
  mismo motivo.
- **`Area::agrupar_asignaturas`**: 2 consultas por alumno (~76 de las 111 que quedan
  en bolfinales simon, ~25 ms), y otras ~5 por alumno en modelos compartidos
  (ausencias totales, comportamiento, disciplina, `bol_ind_periodos`). ~100 ms por
  grupo en total; un modelo que usan todos los formatos.
- **`Grupo::detailed_materias_notas_finales`** (formato 3) y `detailed_materias_notafinal`
  (competencias), una por alumno (~65–80 ms el grupo): pasarlas a una por grupo choca
  con el empate de orden de P5 y no se podría demostrar por sha1.
- **El tamaño de la respuesta**, que ya pesa más que el cálculo: formato 1, **6,6 MB**
  —`asignaturas_perdidas` repite entera cada asignatura perdida con unidades y
  subunidades (3,2 MB, el 48 %), y `definicion_subunidad` y `definicion` llevan el mismo
  texto—; formato 2, **6 MB** —cada asignatura va tres veces (`asignaturas`,
  `areas[].asignaturas`, `asignaturas_perdidas`, ~1,9 MB cada una) y cada unidad lleva
  ~20 columnas de la escala (`created_at`, `icono_*`…, ~2,5 MB)—. Recortarlo es cambiar
  el contrato con el front (y con myvc_front_2 si lo lee): trabajo aparte, con el
  front delante.

## Qué no se propone

- **Índices en `notas`**: no hacen falta para P1 ni P3, y el plan de rendimiento
  pide medir antes. La consulta de P1 ya usa los índices existentes de `ausencias`.
- **Cachear boletines ya calculados**: las notas cambian hasta el cierre y una
  caché desfasada imprime notas viejas; no compensa mientras P1 deja el informe en
  menos de un segundo.

## Orden sugerido

P1 (backend, 16 colegios) → P2a (front) → P3a → medir en producción con P4 →
P3b y P2b/P2c sólo si los números lo piden.

## Cómo se midió

`tools/medir-un-informe.php`, dentro del contenedor, contra una base propia:

```bash
docker cp tools/medir-un-informe.php 8myvc-app-1:/tmp/m.php
T=$(docker exec -e DB_DATABASE=rend_simon -e SOLO_LOGIN=1 8myvc-app-1 php /tmp/m.php POST x)
docker exec -e DB_DATABASE=rend_simon -e TOKEN=$T -e FORMAS=6 8myvc-app-1 \
    php /tmp/m.php PUT /api/bolfinales/detailed-notas-year-group/102 '' 1
# BASE=/app/.worktrees/<sufijo> mide el código de un worktree con la misma base.
```

- La petición va dentro de una transacción que se deshace: nada de lo que el
  endpoint escriba se queda. **El login va fuera**, o el token se deshace con ella.
- El token se saca en un proceso aparte (`SOLO_LOGIN`) y se reutiliza: repetir el
  login da 429.
- `FORMAS=n` imprime las n formas de consulta que más tiempo suman.
- `rend_simon` y `rend_quibdo` son copias hechas para esto, migradas y con la
  clave local puesta al `administrador`; no se tocó ninguna base existente.
