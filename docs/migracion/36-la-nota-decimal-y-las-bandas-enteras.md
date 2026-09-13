# 36 · La nota es decimal y las bandas siguen siendo enteras — el hueco en cada frontera

**Medido el 13 sep 2026** contra el docker de desarrollo, sobre `simonbolivar`, con
MySQL 8.0.46. Salió construyendo la **Fase 4** del
[35](35-el-modelo-de-evaluacion-del-colegio.md) —la rejilla premarcada— y **no es de
la Fase 4**: es un fallo de datos que **ya está ocurriendo en los boletines de hoy**,
sin rejilla, sin competencias y sin desempeños.

**Nada de esto está arreglado.** Este documento sólo lo deja medido, con la consulta
que lo saca, para que la decisión se tome con los números delante.

---

## 1. El mecanismo, en dos líneas

`2026_08_30_200000_notas_finales_en_decimal` —**ya fundida en `main`**— cambió
`notas_finales.nota` de `int` a **`DECIMAL(7,4)`**, y lo hizo por una razón buena: el
77,1 % de las definitivas se estaban guardando redondeadas y eso empataba puestos que
no empataban (esa cifra es suya, no de aquí; está en su propia cabecera).

**Lo que esa migración no tocó, porque no era su asunto, es la escala:**

```
notas_finales.nota                  decimal(7,4)     ← cambió
escalas_de_valoracion.porc_inicial  int              ← no
escalas_de_valoracion.porc_final    int              ← no
```

Y el cruce de toda la casa es `porc_inicial <= nota <= porc_final`. Con una escala
escrita **contigua en enteros** —que es como se escribe una escala— eso deja **un
hueco abierto en cada frontera**:

| banda | cubre | el hueco que deja debajo |
|---|---|---|
| BAJO | 0 – 29 | |
| BÁSICO | 30 – 39 | **(29, 30)** — un 29,5 no casa |
| ALTO | 40 – 45 | **(39, 40)** — un 39,3 no casa |
| SUPERIOR | 46 – 50 | **(45, 46)** — un 45,5 no casa |

> **No es una escala mal montada: es la única forma en que se puede escribir.**
> Mientras la nota era entera, `…29` y `30…` eran contiguas de verdad. Al volverse
> decimal la nota, la misma escala pasó a tener agujeros **sin que nadie la tocara**,
> y no hay forma de escribirla con enteros que no los tenga.

**Los nueve años de `simonbolivar` tienen la misma forma**, y eso es lo que descarta
que sea el descuido de un colegio:

    year_id  bandas  fronteras con hueco
       1..9      4            3

O sea **27 huecos**, tres por año, en los nueve años del colegio.

---

## 2. No es teórico: cuatro definitivas ya están dentro de un hueco

De las **127.748** definitivas vivas de `simonbolivar`, **13 no casan con ninguna
banda**. Trece, una por una:

| nota | year | por qué no casa |
|---|---|---|
| **39,3000** | 8 | **decimal** — entre BÁSICO (…39) y ALTO (40…) |
| **45,0050** | 8 | **decimal** — entre ALTO (…45) y SUPERIOR (46…) |
| **45,0500** | 8 | **decimal** — entre ALTO y SUPERIOR |
| **45,5000** | 8 | **decimal** — entre ALTO y SUPERIOR |
| 51, 51, 51, 52 | 7 | por encima del techo de la escala (acaba en 50) |
| 53 | 4 | por encima del techo |
| 54, 56, 56 | 1 | por encima del techo |
| 55 | 5 | por encima del techo |

**Cuatro por el decimal, y las cuatro del año 8 —el año en curso—.** Las otras nueve
son otro asunto, viejo y distinto: notas por encima del `porc_final` más alto.

Y son **sólo cuatro porque el `ALTER` no recalculó nada**: hoy hay **37** definitivas
con decimales en toda la base —las escritas desde que la migración corrió— y ya cuatro
de ellas están en un hueco. **El día que un colegio recalcule definitivas con la
columna ya decimal, esa proporción deja de ser 4 de 37.**

Como cota de exposición, y **es una cota y no una predicción**: en el año 8 hay **376
de 8.022** definitivas (4,7 %) posadas exactamente sobre el techo de una banda que no
es la última —o sea sobre 29, 39 o 45—. Ésas son las que, al recuperar su parte
decimal, caen en el agujero.

---

## 3. Dónde acaba impreso, que es la parte que importa

**El cruce no es de la rejilla: los boletines de hoy ya lo hacen.** Dos consultas de
`app/Models/Grupo.php` cruzan la definitiva con la escala y **seleccionan
`e.desempenio`**, que es el nombre del nivel que sale en el papel:

| consulta | línea | quién la llama |
|---|---|---|
| `Grupo::detailed_materias_notafinal` | `app/Models/Grupo.php:312` | `Informes/BoletinesController:282`, `Informes/Boletines2Controller:206`, `Informes/NotasActualesAlumnosController:179`, `Models/Nota.php:376` |
| `Grupo::detailed_materias_notas_finales` | `app/Models/Grupo.php:359, 398, 438, 485` | `Informes/Boletines3Controller:217` |

Las dos con la misma forma:

```sql
left join escalas_de_valoracion e
       ON e.porc_inicial <= n.nota and e.porc_final >= n.nota
      and e.deleted_at is null and e.year_id = :year_id
```

Es un `LEFT JOIN`, así que **no falla: devuelve `NULL`**. Corrido tal cual sobre las
cuatro filas decimales, con una fila sana al lado como control:

```
id        alumno  asignatura  nota_asignatura  desempenio
7214014     956      1232          45.5          NULL
7214056    1001      1232          45.005        NULL
7214082    1004      1232          45.05         NULL
7214354    1287      1232          39.3          NULL
7214015       —      1232          46            SUPERIOR     ← control
```

**Cuatro alumnos reales del año en curso cuyo boletín trae el nivel vacío**, con la
nota impresa al lado. Y es el modo de fallo caro de esta casa: **200, sin una línea en
el log, y nadie lo ve** — porque no hay ninguna pantalla donde un nivel que falta se
vea **antes** de que el boletín salga impreso.

> **Lo que NO está comprobado, y se dice para que nadie lo dé por medido:** cómo pinta
> el front un `desempenio` a `null` —si deja el hueco, si escribe «null», o si el
> renglón se cae—. No se condujo el boletín contra el docker **a propósito**: esos
> endpoints recalculan definitivas, y pulsarlos es escribir. Se comprueba pidiendo
> `GET api/boletines/detailed-notas-year/{grupo}` del grupo del alumno **956** en una
> copia, no en la base de trabajo.

---

## 4. La consulta que saca los números

Se corre entera y contesta las dos cosas: el censo y las trece filas. **Lee y no
escribe.**

```sql
-- 1. El censo. Sobre `simonbolivar`; cambia el nombre de la base para otro colegio.
SELECT COUNT(*)                                        AS definitivas,
       SUM(nf.nota <> FLOOR(nf.nota))                  AS con_decimales,
       SUM(e.id IS NULL)                               AS sin_banda,
       SUM(e.id IS NULL AND nf.nota <> FLOOR(nf.nota)) AS sin_banda_por_el_decimal
  FROM notas_finales nf
  INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
  INNER JOIN grupos     g ON g.id = a.grupo_id        AND g.deleted_at IS NULL
  LEFT  JOIN escalas_de_valoracion e
          ON e.year_id = g.year_id AND e.deleted_at IS NULL
         AND e.porc_inicial <= nf.nota AND e.porc_final >= nf.nota;

-- 2. Las que no casan, una por una, con el decimal delante.
SELECT nf.id, nf.nota, g.year_id, (nf.nota <> FLOOR(nf.nota)) AS es_decimal
  FROM notas_finales nf
  INNER JOIN asignaturas a ON a.id = nf.asignatura_id AND a.deleted_at IS NULL
  INNER JOIN grupos     g ON g.id = a.grupo_id        AND g.deleted_at IS NULL
  LEFT  JOIN escalas_de_valoracion e
          ON e.year_id = g.year_id AND e.deleted_at IS NULL
         AND e.porc_inicial <= nf.nota AND e.porc_final >= nf.nota
 WHERE e.id IS NULL
 ORDER BY es_decimal DESC, nf.nota;

-- 3. Cuántas fronteras de cada año dejan hueco. Esto NO depende de los datos:
--    es una propiedad de la escala, y por eso se puede contestar sin notas.
SELECT e.year_id, COUNT(*) AS bandas,
       SUM(EXISTS (SELECT 1 FROM escalas_de_valoracion e2
                    WHERE e2.year_id = e.year_id AND e2.deleted_at IS NULL
                      AND e2.porc_inicial = e.porc_final + 1)) AS fronteras_con_hueco
  FROM escalas_de_valoracion e
 WHERE e.deleted_at IS NULL
 GROUP BY e.year_id ORDER BY e.year_id;
```

**La tercera es la que hay que correr el día del despliegue en los diecisiete**, y es
la barata: contesta sin mirar una sola nota, así que dice **cuántos colegios tienen la
escala expuesta** aunque todavía no tengan ni una definitiva decimal.

**`simonbolivar` es un colegio.** La población de los dieciséis **no se sabe** y no se
puede saber desde aquí.

---

## 5. Las salidas, y ninguna se elige aquí

Se dejan con lo que cuestan, que es lo que hace falta para decidir:

| | qué es | qué cuesta | qué se lleva por delante |
|---|---|---|---|
| **A · redondear al cruzar** | `ROUND(nf.nota)` en las dos consultas de `Grupo` | dos líneas, cero esquema | un 45,5 imprime SUPERIOR y un 45,4 imprime ALTO. **Es reintroducir el redondeo que la migración quitó**, y sólo donde se imprime el nivel — la nota seguiría diciendo 45,5 al lado del nivel de 46 |
| **B · cerrar la banda por abajo** | `porc_inicial <= nota < porc_inicial_de_la_siguiente` | reescribir el cruce en **seis sitios** (`Grupo` ×5, `Unidad`, `Subunidad` ×2, y los dos de la Fase 4) | es el arreglo correcto y **no es una línea**: hay que decidir qué pasa en el techo de la última banda, y hoy nueve notas ya lo pasan |
| **C · volver las bandas decimales** | `ALTER` sobre `porc_inicial`/`porc_final` | una migración, y **la pantalla de escalas del colegio** | no arregla nada solo: 0-29,99 y 30-… deja el mismo hueco más estrecho. Mueve el problema, no lo cierra |
| **D · no hacer nada y enseñarlo** | lo que hace la Fase 4 con `sin_banda` | ya está hecho | **no arregla los boletines de hoy**, que es donde está ocurriendo. Sólo hace que se vea en una pantalla que todavía no usa nadie |

> **La que parece gratis es la A y es la que hay que mirar dos veces.** Redondear al
> cruzar deja impresos, en el mismo renglón, una nota y un nivel que no se corresponden
> —45,5 y SUPERIOR—, que es exactamente la clase de incoherencia que un acudiente sí
> nota. **Y no es reversible barata**: una vez impreso, ese boletín ya salió.

**Lo que la Fase 4 sí hace, y es lo único que aporta hoy:** `GET desempenos/rejilla`
cuenta `alumnos_sin_banda` y marca la celda con `motivo: "sin_banda"`, así que en esa
pantalla —y **sólo** en esa— el agujero se ve antes de imprimirse. Es un detector, no
un arreglo, y el detector está en la pantalla equivocada para este problema: el
problema está en el boletín.

---

## 6. De dónde sale cada cosa

- `database/migrations/2026_08_30_200000_notas_finales_en_decimal.php` — el `ALTER`, y
  el 77,1 % es suyo.
- `database/migrations/2026_09_13_400000_marca_del_desempeno.php` y
  `app/Http/Controllers/DesempenosController::getRejilla` — dónde se enseña
  `sin_banda`, y por qué.
- `tests/Contrato/RejillaPremarcadaTest::test_una_nota_en_el_hueco_entre_dos_bandas_sale_vacia_y_contada`
  — el caso que fija que un hueco se cuenta en vez de callarse.
- Medido sobre `simonbolivar` en el docker el **13 sep 2026, 20:30**, MySQL 8.0.46,
  con las consultas de la §4 tal como están escritas.
