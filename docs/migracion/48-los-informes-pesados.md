# 48 — Los informes pesados: certificados y boletines

24 sep 2026. Encargo de Joseth: analizar y rehacer los endpoints que tardan en
cargar, empezando por certificados y boletines. **Esto es la propuesta; nada de
esto está en `main` salvo este documento y el medidor.** El prototipo de P1 vive en
la rama `sesion/rend` (`.worktrees/rend`).

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
