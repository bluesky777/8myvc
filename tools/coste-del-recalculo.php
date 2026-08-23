<?php

/**
 * Lo que cuesta recalcular una definitiva, medido sobre las asignaturas reales.
 *
 * Existe por una pregunta de Joseth el 24 ago 2026: *«¿no es muy pesado
 * recalcular la definitiva en cada uno de estos sitios?»*. La [fase 3 del plan de
 * definitivas](../docs/migracion/10-definitivas.md) cableó siete disparadores al
 * recalculador único, y el propio plan dejó escrito qué hacer si salía caro —
 * *«la salida no es dejar de recalcular sino recalcular solo la fila de ese
 * alumno»*—. Esto contesta si hace falta.
 *
 * Uso (dentro del contenedor):
 *
 *     docker exec 8myvc-app-1 php tools/coste-del-recalculo.php
 *     docker exec 8myvc-app-1 php tools/coste-del-recalculo.php --cuantas=20
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/coste-del-recalculo.php
 *
 * **No escribe nada**: `calcular()` y `selloDeVersion()` son de sólo lectura, y
 * el UPSERT no se ejecuta a propósito. Medir el coste no puede costar datos.
 *
 * ---
 *
 * **Se mide el caso peor, no la media.** Las asignaturas van ordenadas por número
 * de notas descendente porque quien se queja de que la planilla va lenta es el
 * profesor del grupo grande, no el de la optativa de seis alumnos. Una media
 * sobre 1.500 asignaturas esconde justo la que importa.
 *
 * **Se mide con medianas de 21 repeticiones y alternando el orden**, y eso no es
 * ceremonia: costó una conclusión falsa. La primera versión medía una pasada por
 * asignatura, en orden fijo, y dio **123,8 ms contra 42,5 ms** entre calcular el
 * grupo entero y calcular un solo alumno — un 3× que parecía justificar estrechar
 * la consulta. **Era la caché.** La primera consulta calentaba el buffer pool y la
 * segunda cobraba el beneficio; alternando el orden y tomando medianas, la
 * diferencia real es **1,26×** sobre 1,7 ms, y las filas leídas bajan de 1.753 a
 * 1.669 — **un 5%**.
 *
 * O sea que estrechar `calcular()` a un alumno ahorra **~0,35 ms por pulsación**.
 * Este proyecto ya decidió no encender una caché de contexto que ahorraba 0,75 ms
 * ([02-plan-rendimiento.md](../docs/migracion/02-plan-rendimiento.md)), así que
 * por su propia vara esto es **ruido y no se hace**. El intento está en el
 * plan de rendimiento, con su medición, para que nadie lo vuelva a intentar
 * creyendo que hay un 3× esperando.
 *
 * El porqué de que estrechar no sirva está en el `EXPLAIN`: el plan entra por
 * `notas_subunidad_id_foreign`, o sea que recorre las notas de cada subunidad y
 * descarta las de los otros alumnos **después**. El filtro por alumno no evita la
 * lectura, sólo la suma.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

$opciones = getopt('', ['cuantas::', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2600), PHP_EOL;
    exit(0);
}

$cuantas = isset($opciones['cuantas']) ? max(1, (int) $opciones['cuantas']) : 10;
$base = DB::connection()->getDatabaseName();

echo PHP_EOL."Coste de recalcular — base `{$base}`, las {$cuantas} asignaturas con más notas";
echo PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;

$muestra = DB::select(
    'SELECT u.asignatura_id, u.periodo_id,
            COUNT(DISTINCT n.alumno_id) AS alumnos, COUNT(*) AS notas
       FROM unidades u
       INNER JOIN subunidades s ON s.unidad_id = u.id AND s.deleted_at IS NULL
       INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL
      WHERE u.deleted_at IS NULL
      GROUP BY u.asignatura_id, u.periodo_id
      ORDER BY COUNT(*) DESC
      LIMIT '.$cuantas
);

if ($muestra === []) {
    echo "No hay ninguna asignatura con notas en esta base.".PHP_EOL;
    exit(0);
}

/**
 * La mediana de varias pasadas, que es lo que hay que mirar aquí.
 *
 * La media la manda un solo pico —el primer toque de una asignatura, con el
 * buffer pool frío, cuesta un orden de magnitud más que los siguientes— y ese
 * pico no es lo que paga un profesor tecleando la cuarta nota seguida.
 */
function mediana(array $muestras): float
{
    sort($muestras);
    $n = count($muestras);

    return $n % 2 === 1
        ? $muestras[intdiv($n, 2)]
        : ($muestras[$n / 2 - 1] + $muestras[$n / 2]) / 2;
}

/** @param callable():mixed $que */
function cronometrar(callable $que, int $veces = 21): float
{
    $que();   // una en balde: se mide el caso caliente, no el primer toque

    $ms = [];
    for ($i = 0; $i < $veces; $i++) {
        $t = microtime(true);
        $que();
        $ms[] = (microtime(true) - $t) * 1000;
    }

    return mediana($ms);
}

printf("  %-11s %-8s %8s %7s %11s %10s %12s\n",
    'asignatura', 'periodo', 'alumnos', 'notas', 'calcular', 'sello', 'desactualiz.');
echo '  '.str_repeat('-', 74).PHP_EOL;

$peor = ['calcular' => 0.0, 'sello' => 0.0, 'desac' => 0.0];
$suma = ['calcular' => 0.0, 'sello' => 0.0, 'desac' => 0.0];

foreach ($muestra as $m) {
    $asig = (int) $m->asignatura_id;
    $per = (int) $m->periodo_id;

    $msCalcular = cronometrar(fn () => DefinitivasDeAsignatura::calcular($asig, $per));
    $msSello = cronometrar(fn () => DefinitivasDeAsignatura::selloDeVersion($asig, $per));

    // `estaDesactualizada` es lo que decide si hay que recalcular siquiera, así
    // que su coste es el que se paga SIEMPRE — también cuando no hay nada que
    // hacer, que es el caso común al abrir una pantalla.
    $alumno = DB::selectOne(
        'SELECT alumno_id FROM notas_finales WHERE asignatura_id = ? AND periodo_id = ? LIMIT 1',
        [$asig, $per]
    );

    $msDesac = $alumno === null ? 0.0 : cronometrar(
        fn () => DefinitivasDeAsignatura::estaDesactualizada($asig, $per, (int) $alumno->alumno_id)
    );

    printf("  %-11d %-8d %8d %7d %8.2f ms %7.2f ms %9.2f ms\n",
        $asig, $per, (int) $m->alumnos, (int) $m->notas, $msCalcular, $msSello, $msDesac);

    $peor['calcular'] = max($peor['calcular'], $msCalcular);
    $peor['sello'] = max($peor['sello'], $msSello);
    $peor['desac'] = max($peor['desac'], $msDesac);
    $suma['calcular'] += $msCalcular;
    $suma['sello'] += $msSello;
    $suma['desac'] += $msDesac;
}

$n = count($muestra);

echo PHP_EOL;
printf("  peor caso   calcular %.2f ms · sello %.2f ms · desactualizada %.2f ms\n",
    $peor['calcular'], $peor['sello'], $peor['desac']);
printf("  media       calcular %.2f ms · sello %.2f ms · desactualizada %.2f ms\n",
    $suma['calcular'] / $n, $suma['sello'] / $n, $suma['desac'] / $n);
printf("  el recálculo completo de una nota tecleada = calcular + sello + 1 UPSERT.\n");

echo PHP_EOL.str_repeat('=', 78).PHP_EOL;
echo <<<TXT
Cómo se lee esto:

  · `calcular` es UNA consulta agregada sobre la asignatura entera. Es lo que
    paga `putUpdate` en cada nota tecleada. **Estrecharla a un alumno se probó
    el 24 ago 2026 y salió ruido** (1,26x sobre 1,7 ms): está en el plan de
    rendimiento con su medición, para que no se reintente a ciegas.
  · `sello` y `desactualizada` son lo que se paga para decidir si hace falta
    recalcular. Se pagan SIEMPRE, también cuando no hay nada que hacer.
  · Súmale a todo esto el coste fijo de una petición autenticada, que
    `ConsultasPorPeticionTest` fija aparte.

Antes de optimizar nada: `EXPLAIN` de la consulta de `calcular()`. La regla de
CLAUDE.md vale también para esto — el número de arriba dice si hay problema, no
cuál es.
TXT;
echo PHP_EOL.PHP_EOL;
