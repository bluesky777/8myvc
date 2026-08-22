<?php

/**
 * El tamaño real del daño en `notas_finales`, colegio por colegio.
 *
 * Es la **fase 0** del plan de [docs/migracion/10-definitivas.md](../docs/migracion/10-definitivas.md),
 * y existe porque ese plan empieza por una regla de CLAUDE.md: *antes de
 * optimizar algo, medirlo*. Seis sitios escriben en `notas_finales` con cinco
 * criterios distintos de qué borrar, ninguno transaccional, sobre una tabla **sin
 * clave única**; de ahí salen los tres síntomas que se venían reportando por
 * separado —definitivas que desaparecen, definitivas duplicadas y notas que los
 * profesores juraban haber puesto—.
 *
 * Lo que esto contesta es **si el arreglo hay que acompañarlo de una corrección
 * de datos y de qué tamaño**. Las fases 1 y 2 se despliegan juntas, y la 2 pone
 * un índice único: mientras haya duplicados vivos, ese índice convierte cada uno
 * en un 500. O sea que este número no es informativo, es la condición de entrada.
 *
 * Uso (dentro del contenedor):
 *
 *     docker exec 8myvc-app-1 php tools/salud-de-las-definitivas.php
 *     docker exec 8myvc-app-1 php tools/salud-de-las-definitivas.php --year=8
 *     docker exec 8myvc-app-1 php tools/salud-de-las-definitivas.php --detalle
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/salud-de-las-definitivas.php
 *
 * `--year` acota a un año (por defecto, **todos**: el daño viejo también cuenta,
 * porque los boletines de años pasados se siguen imprimiendo). `--detalle` añade
 * las primeras filas de cada bloque, que es lo que hace falta para ir a mirar un
 * caso concreto en vez de creerse el conteo.
 *
 * **No escribe nada.** Es solo SELECT, a propósito: la corrección de datos va en
 * una migración (CLAUDE.md, «migración o no existe») y con su registro en la
 * bitácora, no en una herramienta que alguien pueda lanzar dos veces.
 *
 * ---
 *
 * **La fórmula que se usa aquí es la del código, no la correcta**, y eso es
 * deliberado:
 *
 *     sum( (u.porcentaje/100) * (s.porcentaje/100) * n.nota )
 *
 * No divide por la suma de los porcentajes. La §9.3 decidió que **no se
 * normaliza**, para que una asignatura mal configurada se vea en la planilla en
 * vez de taparse. Si aquí se normalizara, el bloque 3 marcaría como «discrepa»
 * justo las asignaturas descuadradas —que es lo que mide el bloque 6— y los dos
 * números dirían lo mismo dos veces.
 *
 * **Y el conjunto de alumnos sale de `matriculas`, no de `notas`.** Es la §9.1: la
 * fila existe siempre que exista la matrícula. Por eso el bloque 3 puede
 * distinguir «no existe» de «discrepa», que con el criterio del código —donde el
 * alumno sin notas simplemente no aparece— es indistinguible de «no le tocaba».
 *
 * **El redondeo también es el del código**, y esto costó una medición: la primera
 * versión comparaba con dos decimales y decía que **discrepaban 89.075 de
 * 132.865 — el 67%**. Un número así es más probable que esté mal medido a que sea
 * cierto, y lo estaba: `NotaFinal::calcularAsignaturaPeriodo` envuelve la suma en
 * `cast(... as decimal(4,0))`, o sea que **la definitiva se guarda redondeada a
 * entero** —`notas_finales.nota` es un `int`—. Comparar la suma exacta contra un
 * entero marca como rota cualquier asignatura cuya media no caiga justo en un
 * número redondo, que son casi todas.
 *
 * Es la regla de la 05 §52 aplicada a la propia herramienta: **un detector da una
 * lista de sitios donde mirar, nunca una lista de fallos**, y el primer sitio
 * donde mirar cuando el número sale enorme es el detector.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opciones = getopt('', ['year::', 'detalle', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3200), PHP_EOL;
    exit(0);
}

$soloYear = isset($opciones['year']) ? (int) $opciones['year'] : null;
$detalle = isset($opciones['detalle']);

$base = DB::connection()->getDatabaseName();
$filtroYear = $soloYear !== null ? ' AND g.year_id = '.$soloYear : '';

echo PHP_EOL;
echo "Salud de `notas_finales` — base `{$base}`";
echo $soloYear !== null ? ", año {$soloYear}" : ', todos los años';
echo PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;

/** Imprime un bloque con su título, su número y —si se pidió— sus primeras filas. */
function bloque(string $titulo, int $cuantos, string $unidad, array $ejemplos = [], string $nota = ''): void
{
    global $detalle;

    $marca = $cuantos === 0 ? '  ok ' : ' >>> ';
    echo $marca.$titulo.PHP_EOL;
    echo str_repeat(' ', 6)."{$cuantos} {$unidad}".PHP_EOL;

    if ($nota !== '') {
        foreach (explode("\n", wordwrap($nota, 68)) as $linea) {
            echo str_repeat(' ', 6).$linea.PHP_EOL;
        }
    }

    if ($detalle && $ejemplos !== []) {
        echo PHP_EOL;
        foreach (array_slice($ejemplos, 0, 10) as $fila) {
            echo str_repeat(' ', 8).json_encode($fila, JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
    }

    echo PHP_EOL;
}

// ---------------------------------------------------------------------------
// 1. Duplicados en `notas_finales`, y de qué tipo es cada uno.
//
// El tipo importa para la fase 2: la §9.2 decidió que entre definitivas
// duplicadas **gana la manual**, así que un `auto+manual` se resuelve solo y un
// `manual+manual` necesita el desempate por `id`. Contarlos juntos escondería
// cuántos casos hay que decidir de verdad.
// ---------------------------------------------------------------------------
$duplicados = DB::select(
    'SELECT nf.alumno_id, nf.asignatura_id, nf.periodo_id, COUNT(*) AS filas,
            SUM(CASE WHEN nf.manual = 1 THEN 1 ELSE 0 END) AS manuales
       FROM notas_finales nf
       INNER JOIN asignaturas a ON a.id = nf.asignatura_id
       INNER JOIN grupos g ON g.id = a.grupo_id'.
    ($soloYear !== null ? ' AND g.year_id = '.$soloYear : '').
    ' GROUP BY nf.alumno_id, nf.asignatura_id, nf.periodo_id
       HAVING COUNT(*) > 1
       ORDER BY filas DESC'
);

$porTipo = ['auto+auto' => 0, 'auto+manual' => 0, 'manual+manual' => 0];
foreach ($duplicados as $d) {
    $manuales = (int) $d->manuales;
    $total = (int) $d->filas;

    if ($manuales === 0) {
        $porTipo['auto+auto']++;
    } elseif ($manuales === $total) {
        $porTipo['manual+manual']++;
    } else {
        $porTipo['auto+manual']++;
    }
}

bloque(
    '1. Definitivas duplicadas — (alumno, asignatura, periodo) con más de una fila',
    count($duplicados),
    'combinaciones duplicadas · '.
        "auto+auto: {$porTipo['auto+auto']} · ".
        "auto+manual: {$porTipo['auto+manual']} · ".
        "manual+manual: {$porTipo['manual+manual']}",
    array_map(fn ($d) => (array) $d, $duplicados),
    $duplicados === []
        ? 'La clave única de la fase 2 se puede poner sin limpiar nada.'
        : 'Hay que limpiarlos ANTES del índice único de la fase 2, o cada uno '.
          'de estos se convierte en un 500. Los `manual+manual` son los únicos '.
          'que necesitan el desempate por `id` de la §9.2.'
);

// ---------------------------------------------------------------------------
// 2. Duplicados en `notas`. Son los que el profesor puede editar dos veces y
//    los que hoy cuentan DOS veces en la definitiva, así que limpiarlos la
//    cambia. Por eso van a la bitácora en la fase 2.
// ---------------------------------------------------------------------------
$notasDup = DB::select(
    'SELECT n.subunidad_id, n.alumno_id, COUNT(*) AS filas,
            MIN(n.nota) AS minima, MAX(n.nota) AS maxima
       FROM notas n
       INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
       INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
       INNER JOIN asignaturas a ON a.id = u.asignatura_id
       INNER JOIN grupos g ON g.id = a.grupo_id'.$filtroYear.
    ' WHERE n.deleted_at IS NULL
       GROUP BY n.subunidad_id, n.alumno_id
       HAVING COUNT(*) > 1
       ORDER BY filas DESC'
);

$discrepan = 0;
foreach ($notasDup as $n) {
    if ((float) $n->minima !== (float) $n->maxima) {
        $discrepan++;
    }
}

bloque(
    '2. Notas duplicadas — (subunidad, alumno) con más de una nota viva',
    count($notasDup),
    "combinaciones duplicadas · con valores distintos entre sí: {$discrepan}",
    array_map(fn ($n) => (array) $n, $notasDup),
    $notasDup === []
        ? ''
        : 'Las que tienen valores distintos son las que cambian la definitiva al '.
          'limpiarlas: hoy las dos cuentan en la suma. Gana la más alta (§9.2).'
);

// ---------------------------------------------------------------------------
// 3. Definitivas contra el cálculo, separando los tres estados.
//
// El conjunto de partida es (matrícula viva × asignatura de su grupo × periodo
// del año), que es lo que la §9.1 dice que DEBE existir — no lo que hoy existe.
// ---------------------------------------------------------------------------
// El cálculo va en UNA pasada agregada sobre `notas` y se cruza después. Escrito
// con subconsultas correlacionadas —que es como se lee mejor— tardaba minutos:
// son matrículas × asignaturas × periodos ejecuciones sobre una tabla de 1,16
// millones de filas. Es el mismo criterio del plan de rendimiento aplicado a la
// herramienta que lo mide.
DB::statement('DROP TEMPORARY TABLE IF EXISTS calculo_definitivas');
DB::statement(
    'CREATE TEMPORARY TABLE calculo_definitivas (
        alumno_id INT, asignatura_id INT, periodo_id INT,
        notas INT, calculada DECIMAL(12,4),
        PRIMARY KEY (alumno_id, asignatura_id, periodo_id)
     ) AS
     SELECT n.alumno_id, u.asignatura_id, u.periodo_id,
            COUNT(*) AS notas,
            SUM((u.porcentaje / 100) * ((s.porcentaje / 100) * n.nota)) AS calculada
       FROM notas n
       INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
       INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
      WHERE n.deleted_at IS NULL
      GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id'
);

$comparacion = DB::select(
    'SELECT
        SUM(CASE WHEN t.nf_id IS NULL THEN 1 ELSE 0 END) AS no_existe,
        SUM(CASE WHEN t.nf_id IS NOT NULL AND t.manual = 0 AND COALESCE(t.notas, 0) = 0
                  AND ROUND(t.nota, 2) = 0 THEN 1 ELSE 0 END) AS cero_sin_notas,
        SUM(CASE WHEN t.nf_id IS NOT NULL AND t.manual = 0 AND COALESCE(t.notas, 0) > 0
                  AND t.nota <> CAST(COALESCE(t.calculada, 0) AS DECIMAL(4,0)) THEN 1 ELSE 0 END) AS discrepa,
        SUM(CASE WHEN t.nf_id IS NOT NULL AND t.manual = 1 THEN 1 ELSE 0 END) AS manuales,
        COUNT(*) AS deberian_existir
     FROM (
        SELECT nf.id AS nf_id, nf.nota, COALESCE(nf.manual, 0) AS manual,
               c.notas, c.calculada
          FROM matriculas m
          INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL'.$filtroYear.'
          INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
          INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
          LEFT JOIN notas_finales nf ON nf.alumno_id = m.alumno_id
               AND nf.asignatura_id = a.id AND nf.periodo_id = p.id
          LEFT JOIN calculo_definitivas c ON c.alumno_id = m.alumno_id
               AND c.asignatura_id = a.id AND c.periodo_id = p.id
         WHERE m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS")
     ) t'
)[0];

// Los ejemplos van aparte y solo con `--detalle`: la consulta de arriba es un
// conteo y esta trae filas, así que en la corrida normal no se paga.
$ejemplosDiscrepan = [];
$ejemplosNoExisten = [];

if ($detalle) {
    $ejemplosDiscrepan = DB::select(
        'SELECT nf.id AS nf_id, nf.alumno_id, nf.asignatura_id, nf.periodo_id,
                nf.nota AS guardada, CAST(c.calculada AS DECIMAL(4,0)) AS calculada,
                c.notas, nf.created_at
           FROM notas_finales nf
           INNER JOIN asignaturas a ON a.id = nf.asignatura_id
           INNER JOIN grupos g ON g.id = a.grupo_id'.$filtroYear.'
           INNER JOIN calculo_definitivas c ON c.alumno_id = nf.alumno_id
                AND c.asignatura_id = nf.asignatura_id AND c.periodo_id = nf.periodo_id
          WHERE COALESCE(nf.manual, 0) = 0
            AND nf.nota <> CAST(c.calculada AS DECIMAL(4,0))
          ORDER BY ABS(nf.nota - c.calculada) DESC
          LIMIT 10'
    );

    $ejemplosNoExisten = DB::select(
        'SELECT m.alumno_id, a.id AS asignatura_id, p.id AS periodo_id, g.year_id,
                COALESCE(c.notas, 0) AS notas
           FROM matriculas m
           INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL'.$filtroYear.'
           INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
           INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
           LEFT JOIN notas_finales nf ON nf.alumno_id = m.alumno_id
                AND nf.asignatura_id = a.id AND nf.periodo_id = p.id
           LEFT JOIN calculo_definitivas c ON c.alumno_id = m.alumno_id
                AND c.asignatura_id = a.id AND c.periodo_id = p.id
          WHERE m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS") AND nf.id IS NULL
          ORDER BY COALESCE(c.notas, 0) DESC
          LIMIT 10'
    );
}

bloque(
    '3. Definitivas contra el cálculo',
    (int) $comparacion->no_existe,
    'filas que DEBERÍAN existir y no existen '.
        '(de '.(int) $comparacion->deberian_existir.' combinaciones matrícula×asignatura×periodo)',
    array_map(fn ($f) => (array) $f, $ejemplosNoExisten),
    'La §9.1 decidió que la fila existe siempre que exista la matrícula, así que '.
    '«no existe» es un fallo y no un caso normal. Hoy ningún escritor la crea '.
    'para el alumno sin notas. Los ejemplos van ordenados por cuántas notas '.
    'tiene detrás: los de arriba son alumnos CON notas y sin definitiva, que es '.
    'el caso peor y el que no se explica por la §9.1 sino por la §1.'
);

bloque(
    '3.1 De las que sí existen y son automáticas',
    (int) $comparacion->discrepa,
    'discrepan del cálculo teniendo notas detrás · '.
        'con 0 y sin ninguna nota: '.(int) $comparacion->cero_sin_notas.' · '.
        'manuales (no se comparan): '.(int) $comparacion->manuales,
    array_map(fn ($f) => (array) $f, $ejemplosDiscrepan),
    'Las de «0 sin notas» no son un error de cálculo: son las que la §1 deja '.
    'atrás cuando un DELETE masivo repone solo a quien tenía notas. Se separan '.
    'porque su arreglo es otro. Los ejemplos van por diferencia descendente: la '.
    'de arriba es la definitiva que más se aleja de sus notas.'
);

// ---------------------------------------------------------------------------
// 4. `periodo` NULL o distinto del `numero` de su `periodo_id`.
//
// Tres de los seis escritores identifican la fila por `periodo` y no por
// `periodo_id`. Si las dos columnas no concuerdan, cada uno ve una fila
// distinta — que es la mitad de por qué se duplican.
// ---------------------------------------------------------------------------
$periodoMalo = DB::select(
    'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo, p.numero
       FROM notas_finales nf
       INNER JOIN asignaturas a ON a.id = nf.asignatura_id
       INNER JOIN grupos g ON g.id = a.grupo_id'.$filtroYear.'
       LEFT JOIN periodos p ON p.id = nf.periodo_id
      WHERE nf.periodo IS NULL OR p.id IS NULL OR nf.periodo <> p.numero'
);

bloque(
    '4. `periodo` que no concuerda con `periodo_id`',
    count($periodoMalo),
    'filas',
    array_map(fn ($f) => (array) $f, $periodoMalo),
    'Tres de los seis escritores buscan la fila por `periodo` y los otros por '.
    '`periodo_id`. Donde no concuerdan, cada uno ve una fila distinta y ninguno '.
    'encuentra la del otro: es la mitad de por qué se duplican.'
);

// ---------------------------------------------------------------------------
// 5. `created_at` imposible — la basura de la §1.2.
//
// El INSERT de `NotaFinal::calcularAsignaturaPeriodo` tiene nueve columnas y
// nueve valores, pero `updated_by` no está en la lista: `created_at` recibe el
// `user_id`.
//
// **Y lo que se guarda no es ese número**, que es lo que había que medir para
// saberlo: un entero no es una fecha válida, y con `'strict' => false` en
// `config/database.php` MySQL no falla, lo convierte en `0000-00-00 00:00:00`.
// Medido: las 732 filas del colegio de desarrollo llevan la fecha cero, ninguna
// lleva el id. La §1.2 acertó el mecanismo y no el resultado, y la diferencia
// importa para la limpieza: no hay ningún `user_id` que recuperar de ahí.
//
// El rango de abajo las coge igual —la fecha cero es menor que 2000— y de paso
// cogería la variante que sí guardara el número si algún colegio tuviera el modo
// estricto puesto.
// ---------------------------------------------------------------------------
$fechaMala = DB::select(
    'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.created_at, nf.updated_at
       FROM notas_finales nf
       INNER JOIN asignaturas a ON a.id = nf.asignatura_id
       INNER JOIN grupos g ON g.id = a.grupo_id'.$filtroYear.'
      WHERE nf.created_at IS NULL
         OR nf.created_at < "2000-01-01"
         OR nf.created_at > DATE_ADD(NOW(), INTERVAL 1 DAY)'
);

bloque(
    '5. `created_at` imposible',
    count($fechaMala),
    'filas',
    array_map(fn ($f) => (array) $f, $fechaMala),
    'El INSERT de la §1.2 tiene las columnas desalineadas y `created_at` recibe '.
    'el `user_id` — que MySQL, sin modo estricto, guarda como `0000-00-00`. '.
    'Sirve además como huella: estas filas las escribió ese camino y no otro.'
);

// ---------------------------------------------------------------------------
// 6. Porcentajes descuadrados. Es lo que hace que la fórmula sin normalizar dé
//    un resultado distinto del que el profesor espera — y la §9.3 decidió que
//    se vea, así que esto mide cuánto se va a ver.
// ---------------------------------------------------------------------------
$unidadesMal = DB::select(
    'SELECT u.asignatura_id, u.periodo_id, SUM(u.porcentaje) AS suma, COUNT(*) AS unidades
       FROM unidades u
       INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
       INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL'.$filtroYear.'
      WHERE u.deleted_at IS NULL
      GROUP BY u.asignatura_id, u.periodo_id
     HAVING ROUND(SUM(u.porcentaje), 2) <> 100
      ORDER BY ABS(SUM(u.porcentaje) - 100) DESC'
);

$subunidadesMal = DB::select(
    'SELECT s.unidad_id, u.asignatura_id, u.periodo_id,
            SUM(s.porcentaje) AS suma, COUNT(*) AS subunidades
       FROM subunidades s
       INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
       INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
       INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL'.$filtroYear.'
      WHERE s.deleted_at IS NULL
      GROUP BY s.unidad_id, u.asignatura_id, u.periodo_id
     HAVING ROUND(SUM(s.porcentaje), 2) <> 100
      ORDER BY ABS(SUM(s.porcentaje) - 100) DESC'
);

bloque(
    '6. Porcentajes que no suman 100',
    count($unidadesMal),
    '(asignatura, periodo) cuyas UNIDADES no suman 100 · '.
        count($subunidadesMal).' unidades cuyas SUBUNIDADES no suman 100',
    array_merge(
        array_map(fn ($f) => ['unidades_de' => (array) $f], array_slice($unidadesMal, 0, 5)),
        array_map(fn ($f) => ['subunidades_de' => (array) $f], array_slice($subunidadesMal, 0, 5))
    ),
    'La fórmula no normaliza y la §9.3 decidió que siga así, para que la '.
    'asignatura mal configurada se vea en la planilla. Esto dice en cuántas se '.
    'va a ver. No es una lista de fallos del código: es una lista de '.
    'asignaturas mal configuradas por su profesor.'
);

// ---------------------------------------------------------------------------
// 7. Subunidades vivas sin nota para algún alumno matriculado — el hueco de la
//    §5.1, que mide cuántas definitivas están calculadas DE MENOS ahora mismo.
// ---------------------------------------------------------------------------
$huecos = DB::select(
    'SELECT COUNT(*) AS faltan FROM (
        SELECT s.id, m.alumno_id
          FROM subunidades s
          INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
          INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.deleted_at IS NULL
          INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL'.$filtroYear.'
          INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
               AND m.estado IN ("MATR", "ASIS")
          LEFT JOIN notas n ON n.subunidad_id = s.id AND n.alumno_id = m.alumno_id
               AND n.deleted_at IS NULL
         WHERE s.deleted_at IS NULL AND n.id IS NULL
     ) t'
)[0];

bloque(
    '7. Subunidades vivas sin nota para un alumno matriculado',
    (int) $huecos->faltan,
    '(subunidad, alumno) sin nota',
    [],
    'Es el hueco de la §5.1: la subunidad existe y aporta al 100%, pero sus '.
    'notas no las crea nadie hasta que alguien abre /notas en el navegador. '.
    'Mientras tanto la definitiva se calcula sin ese aporte. Desde Flutter, que '.
    'crea subunidades y nunca llama a /notas, puede durar días. Ojo: parte de '.
    'este número es normal —una subunidad recién creada aún no tiene notas—, '.
    'así que lo que importa es su evolución y su reparto, no el número suelto.'
);

echo str_repeat('=', 78).PHP_EOL;
echo 'Sin --detalle solo se ven los conteos. Con él salen las primeras filas de'.PHP_EOL;
echo 'cada bloque, que es lo que hace falta para ir a mirar un caso de verdad.'.PHP_EOL.PHP_EOL;
