<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * **La medición de la Fase 5**: qué comparten de verdad los tres boletines de
 * periodo, preguntándoselo a la aplicación y no al código.
 *
 * Existe porque la Fase 5 del doc 35 pide cuatro cosas —qué consulta hace cada
 * uno, dónde divergieron, qué es maqueta y qué es dato, y qué instantánea cubre
 * cada rama— y **las tres primeras no se pueden contestar leyendo el PHP**: dos
 * de las divergencias que salieron aquí (`number_format` sobre la definitiva y
 * el `unset($alumno->asignaturas)` del tercero) se leen como inocentes y cambian
 * lo que sale impreso.
 *
 * Se ejecuta **dentro del worktree**, levantando su propio kernel HTTP, porque
 * nginx sirve `/app` —el árbol principal— y desde un worktree no hay puerto por
 * el que llamar. Es el mismo patrón de `tools/probar-desempenos-en-el-docker.php`.
 *
 *   docker exec -w /app/.worktrees/f6 8myvc-app-1 php tools/comparar-los-tres-boletines.php
 *
 * **No escribe nada.** Los tres caminos que pide son de lectura; el único que
 * escribiría —`boletines/detailed-notas` con UN alumno pedido, que recalcula
 * definitivas desactualizadas— se llama igual a propósito, porque es justo una de
 * las divergencias que hay que medir, y el recálculo no destruye (doc 10 §1.1).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function pedir(string $metodo, string $uri, array $cuerpo = [], ?string $token = null): array
{
    global $kernel;

    $peticion = Request::create(
        $uri,
        $metodo,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']
            + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
        $cuerpo === [] ? null : json_encode($cuerpo)
    );

    DB::flushQueryLog();
    DB::enableQueryLog();
    $t0 = microtime(true);
    $respuesta = $kernel->handle($peticion);
    $ms = (microtime(true) - $t0) * 1000;
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [
        'estado' => $respuesta->getStatusCode(),
        'cuerpo' => json_decode((string) $respuesta->getContent(), true),
        'ms' => $ms,
        'consultas' => $consultas,
    ];
}

function token(string $usuario, string $clave): ?string
{
    foreach ([0, 5, 15, 40] as $espera) {
        if ($espera > 0) {
            sleep($espera);
        }

        $r = pedir('POST', '/api/login/credentials', ['username' => $usuario, 'password' => $clave]);

        if (isset($r['cuerpo']['el_token'])) {
            return $r['cuerpo']['el_token'];
        }

        if ($r['estado'] !== 429) {
            echo '  login de '.$usuario.': '.$r['estado'].' '
                .mb_substr(json_encode($r['cuerpo']) ?: '', 0, 160).PHP_EOL;

            return null;
        }
    }

    return null;
}

/** Todos los caminos de campo de una respuesta, hasta `$prof` niveles de lista. */
function caminos($valor, string $pre = '', array &$salida = [], int $prof = 0): array
{
    if ($prof > 8) {
        return $salida;
    }

    if (is_array($valor) && $valor !== [] && array_keys($valor) !== range(0, count($valor) - 1)) {
        foreach ($valor as $k => $v) {
            $c = $pre === '' ? (string) $k : $pre.'.'.$k;
            $salida[$c] = true;
            caminos($v, $c, $salida, $prof + 1);
        }
    } elseif (is_array($valor)) {
        foreach (array_slice($valor, 0, 3) as $v) {
            caminos($v, $pre.'[]', $salida, $prof + 1);
        }
    }

    return $salida;
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

$jefe = token('administrador', 'patreongreat');

if ($jefe === null) {
    echo 'Sin token no se puede seguir.'.PHP_EOL;
    exit(1);
}

// El grupo con más frases de asignatura del periodo del usuario: es el que más
// tiene que imprimir, así que es donde las divergencias se ven.
$yo = DB::selectOne('SELECT periodo_id FROM users WHERE username = ?', ['administrador']);
$periodo = DB::selectOne('SELECT id, numero, year_id FROM periodos WHERE id = ?', [$yo->periodo_id]);

$grupo = DB::selectOne(
    'SELECT a.grupo_id, g.nombre, g.caritas, COUNT(*) AS frases
       FROM frases_asignatura fa
       INNER JOIN asignaturas a ON a.id = fa.asignatura_id AND a.deleted_at IS NULL
       INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
      WHERE fa.periodo_id = ? AND fa.deleted_at IS NULL
      GROUP BY a.grupo_id, g.nombre, g.caritas
      ORDER BY frases DESC LIMIT 1',
    [$periodo->id]
);

if ($grupo === null) {
    echo 'No hay ningún grupo con frases en el periodo '.$periodo->id.'.'.PHP_EOL;
    exit(1);
}

$alumno = DB::selectOne(
    'SELECT m.alumno_id FROM matriculas m
      WHERE m.grupo_id = ? AND m.deleted_at IS NULL
        AND m.estado IN ("MATR","ASIS","PREM")
      ORDER BY m.alumno_id LIMIT 1',
    [$grupo->grupo_id]
);

echo 'periodo '.$periodo->id.' (nº '.$periodo->numero.', año '.$periodo->year_id.')'.PHP_EOL;
echo 'grupo '.$grupo->grupo_id.' — caritas='.$grupo->caritas.', '.$grupo->frases.' frases'.PHP_EOL;
echo 'alumno '.$alumno->alumno_id.PHP_EOL.PHP_EOL;

$cuerpo = ['requested_alumnos' => [['alumno_id' => $alumno->alumno_id]], 'periodo_a_calcular' => 4];

$r = [];
foreach (['boletines', 'boletines2', 'boletines3'] as $fam) {
    $r[$fam] = pedir('PUT', '/api/'.$fam.'/detailed-notas/'.$grupo->grupo_id, $cuerpo, $jefe);
    printf("  %-12s %d   %6.0f ms   %4d consultas   %8d bytes\n",
        $fam, $r[$fam]['estado'], $r[$fam]['ms'], $r[$fam]['consultas'],
        strlen(json_encode($r[$fam]['cuerpo']) ?: ''));
}

echo PHP_EOL;

$c = [];
foreach ($r as $fam => $x) {
    $s = [];
    $c[$fam] = array_keys(caminos($x['cuerpo'], '', $s));
}

$comunes = array_intersect($c['boletines'], $c['boletines2'], $c['boletines3']);
$union = array_unique(array_merge($c['boletines'], $c['boletines2'], $c['boletines3']));

printf("campos: b1=%d  b2=%d  b3=%d   unión=%d   comunes a los tres=%d (%.0f %%)\n",
    count($c['boletines']), count($c['boletines2']), count($c['boletines3']),
    count($union), count($comunes), 100 * count($comunes) / max(1, count($union)));

foreach (['boletines', 'boletines2', 'boletines3'] as $fam) {
    $otros = [];
    foreach ($c as $g => $v) {
        if ($g !== $fam) {
            $otros = array_merge($otros, $v);
        }
    }

    $solo = array_values(array_diff($c[$fam], $otros));
    sort($solo);
    echo PHP_EOL.'── sólo en '.$fam.': '.count($solo).PHP_EOL;
    foreach (array_slice($solo, 0, 70) as $s) {
        echo '     '.$s.PHP_EOL;
    }
}

// ── Las dos divergencias que sólo se ven con el valor delante ────────────────
echo PHP_EOL.'── el tercero y su `unset($alumno->asignaturas)`'.PHP_EOL;
foreach (['boletines', 'boletines2', 'boletines3'] as $fam) {
    $al = $r[$fam]['cuerpo'][2][0] ?? null;
    echo sprintf('     %-12s asignaturas=%s  areas=%s',
        $fam,
        isset($al['asignaturas']) ? count($al['asignaturas']) : 'NO VIENE',
        isset($al['areas']) ? count($al['areas']) : 'NO VIENE').PHP_EOL;
}

echo PHP_EOL.'── el cuarto elemento de la respuesta (escalas_de_valoracion)'.PHP_EOL;
foreach (['boletines', 'boletines2', 'boletines3'] as $fam) {
    echo sprintf('     %-12s elementos=%d', $fam, count($r[$fam]['cuerpo'] ?? [])).PHP_EOL;
}
