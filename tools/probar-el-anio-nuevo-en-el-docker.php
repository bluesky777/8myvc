<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Le pide de verdad a la aplicación que cree el año siguiente, contra la base de
 * DESARROLLO, y cuenta qué heredó el año nuevo: **tabla por tabla**.
 *
 *   docker exec -w /app/.worktrees/anio 8myvc-app-1 php tools/probar-el-anio-nuevo-en-el-docker.php
 *
 * Existe porque leer el PHP no basta —la copia de `desempenos_por_defecto`
 * depende de tres remapeos y de en qué orden corren los bloques de `postStore`—
 * y porque el servidor web del contenedor sirve `/app`, o sea el árbol
 * principal: desde un worktree no hay puerto por el que llamar. Esto levanta el
 * kernel HTTP **de este árbol** y le mete la petición por la misma puerta que
 * nginx, con sus guards, su middleware y su base.
 *
 * ## NO deja nada escrito, y ésa es la diferencia con sus dos hermanos
 *
 * `probar-competencias-en-el-docker.php` y `probar-desempenos-en-el-docker.php`
 * escriben y borran después lo suyo. Aquí eso no vale: crear un año escribe en
 * **diez tablas** —grupos, asignaturas, periodos, escalas, frases, disciplina,
 * plantilla y el plan de área— y además **apaga el año actual del colegio** si
 * se le pide `actual`. Borrar todo eso a mano es más código que el que se está
 * probando, y lo que no se borre bien se queda en la base de desarrollo con cara
 * de dato.
 *
 * Así que la petición va **dentro de una transacción que se deshace siempre**,
 * que es lo mismo que hace `DatabaseTransactions` en la suite. El kernel usa la
 * misma conexión, así que la petición corre dentro; el `finally` la deshace pase
 * lo que pase, incluida una excepción.
 *
 * **Lo que esto NO prueba, dicho antes de que alguien lea los números como un
 * OK**: que el año quede bien después de un `commit`. Se deshace todo, así que
 * no se ve ninguna interacción con lo que otra petición pudiera hacer a la vez.
 * Lo que sí prueba es lo que se quería saber: que la copia **ocurre**, con qué
 * población, y que las referencias del plan de área apuntan al año nuevo.
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

    $respuesta = $kernel->handle($peticion);

    return [
        'estado' => $respuesta->getStatusCode(),
        'cuerpo' => json_decode((string) $respuesta->getContent(), true),
    ];
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

$login = pedir('POST', '/api/login/credentials', [
    'username' => 'administrador',
    'password' => 'patreongreat',
]);
$token = $login['cuerpo']['el_token'] ?? null;

if ($token === null) {
    echo 'Sin token no se puede seguir. Respuesta: '.json_encode($login['cuerpo']).PHP_EOL;
    exit(1);
}

echo 'login administrador: '.$login['estado'].PHP_EOL;

// Las tablas por año, preguntadas a la base y no escritas a mano: es el mismo
// censo que hace `CentinelaDeLasTablasDelAnioNuevoTest`, y aquí sirve para que
// el recuento de abajo no se quede viejo el día que aparezca una tabla nueva.
$porAnio = array_map(
    static fn (object $f): string => (string) $f->tabla,
    DB::select("SELECT c.TABLE_NAME AS tabla
                  FROM information_schema.COLUMNS c
                  JOIN information_schema.TABLES t
                    ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                 WHERE c.TABLE_SCHEMA = DATABASE()
                   AND c.COLUMN_NAME = 'year_id'
                   AND t.TABLE_TYPE = 'BASE TABLE'
                 ORDER BY c.TABLE_NAME")
);

DB::beginTransaction();

try {
    $ultimo = DB::selectOne('SELECT * FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1');

    echo 'año origen: '.$ultimo->year.' (id '.$ultimo->id.')'.PHP_EOL.PHP_EOL;

    // ── El plan de área del año origen ───────────────────────────────────────
    //
    // Se siembra aquí porque en desarrollo las dos tablas están vacías —nacieron
    // el 13 sep 2026—, y un origen vacío haría que todo lo de abajo saliera «0 de
    // 0», que es un verde que no significa nada.
    $materia = DB::selectOne("SELECT id, materia FROM materias WHERE materia LIKE 'LENGUA CASTELLANA%' AND deleted_at IS NULL LIMIT 1");
    $grado = DB::selectOne("SELECT id, nombre FROM grados WHERE nombre = 'Sexto' LIMIT 1");
    $periodos = DB::select('SELECT id, numero FROM periodos WHERE year_id=? AND deleted_at IS NULL ORDER BY numero', [$ultimo->id]);

    echo 'periodos del año origen: '.count($periodos).' → '
        .implode(', ', array_map(static fn ($p) => 'n'.$p->numero.'=#'.$p->id, $periodos)).PHP_EOL;

    $competenciaId = DB::table('competencias')->insertGetId([
        'year_id' => $ultimo->id, 'materia_id' => $materia->id, 'grado_id' => $grado->id,
        'alumno_id' => null, 'definicion' => 'SONDA · Comprende textos de su entorno',
        'orden' => 0, 'codigo_men' => 'LEN-1-3-PT-1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Uno por cada periodo que tenga el año origen, **sean los que sean**. En
    // desarrollo el año 2026 tiene **UNO**, no cuatro: es el que creó esta misma
    // ruta antes del arreglo del 30 ago 2026, y está escrito en el docblock de
    // `crearLosPeriodos`. Dar por hechos cuatro aquí habría sido un `Undefined
    // array key 1` — y, peor, un número de abajo que no se podría comparar con
    // nada.
    foreach ($periodos as $i => $periodo) {
        DB::table('desempenos_por_defecto')->insert([
            'year_id' => $ultimo->id, 'materia_id' => $materia->id, 'grado_id' => $grado->id,
            'periodo_id' => $periodo->id, 'competencia_id' => $competenciaId,
            'tipo' => 'Cognitivo', 'definicion' => 'SONDA · desempeño del periodo '.$periodo->numero,
            'orden' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Y uno en un periodo que el año nuevo **no va a tener**: el año nuevo nace
    // con cuatro (`CalendarioDePeriodos::CANTIDAD`), así que un noveno periodo no
    // tiene equivalente por construcción. Es el camino que se salta, y sin esta
    // fila no se recorre nunca.
    $noveno = DB::table('periodos')->insertGetId([
        'numero' => 9, 'actual' => 0, 'year_id' => $ultimo->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('desempenos_por_defecto')->insert([
        'year_id' => $ultimo->id, 'materia_id' => $materia->id, 'grado_id' => $grado->id,
        'periodo_id' => $noveno, 'competencia_id' => null, 'tipo' => null,
        'definicion' => 'SONDA · el del noveno periodo', 'orden' => 99,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    echo 'sembrado en el año origen: 1 competencia (#'.$competenciaId.'), '
        .count($periodos).' desempeños en sus periodos y 1 en un noveno periodo sin equivalente'.PHP_EOL.PHP_EOL;

    $antes = [];

    foreach ($porAnio as $tabla) {
        $antes[$tabla] = (int) DB::table($tabla)->where('year_id', $ultimo->id)->count();
    }

    // ── La petición de verdad ────────────────────────────────────────────────
    $r = pedir('POST', '/api/years/store', [
        'year' => ((int) $ultimo->year) + 1,
        // A propósito `false`: `postStore` apaga los demás años cuando se le pide
        // `true`, y aunque esto se deshaga entero, un fallo a mitad dejaría al
        // colegio de desarrollo sin año actual.
        'actual' => false,
        'nombre_colegio' => $ultimo->nombre_colegio,
        'abrev_colegio' => $ultimo->abrev_colegio,
        'nota_minima_aceptada' => $ultimo->nota_minima_aceptada,
        'resolucion' => $ultimo->resolucion,
        'codigo_dane' => $ultimo->codigo_dane,
        'encabezado_certificado' => $ultimo->encabezado_certificado,
        'telefono' => $ultimo->telefono,
        'celular' => $ultimo->celular,
        'unidad_displayname' => $ultimo->unidad_displayname,
        'unidades_displayname' => $ultimo->unidades_displayname,
        'genero_unidad' => $ultimo->genero_unidad,
        'subunidad_displayname' => $ultimo->subunidad_displayname,
        'subunidades_displayname' => $ultimo->subunidades_displayname,
        'genero_subunidad' => $ultimo->genero_subunidad,
        'website' => $ultimo->website,
        'website_myvc' => $ultimo->website_myvc,
        'alumnos_can_see_notas' => $ultimo->alumnos_can_see_notas,
    ], $token);

    echo 'POST /api/years/store → '.$r['estado'].PHP_EOL;

    $nuevo = $r['cuerpo']['id'] ?? null;

    if ($nuevo === null) {
        echo 'La respuesta no trae id: '.substr((string) json_encode($r['cuerpo']), 0, 400).PHP_EOL;
        exit(1);
    }

    echo 'año nuevo: '.$r['cuerpo']['year'].' (id '.$nuevo.'), periodos en la respuesta: '
        .count($r['cuerpo']['periodos'] ?? []).PHP_EOL.PHP_EOL;

    // ── Tabla por tabla: lo que tenía el origen y lo que heredó el nuevo ─────
    printf("  %-26s %8s %8s   %s\n", 'tabla con year_id', 'origen', 'nuevo', '');
    echo '  '.str_repeat('─', 60).PHP_EOL;

    foreach ($porAnio as $tabla) {
        $despues = (int) DB::table($tabla)->where('year_id', $nuevo)->count();
        $marca = $despues > 0 ? 'HEREDA' : ($antes[$tabla] > 0 ? 'no copia' : '');

        printf("  %-26s %8d %8d   %s\n", $tabla, $antes[$tabla], $despues, $marca);
    }

    // ── Y las referencias del plan de área, que es lo que no se ve contando ──
    echo PHP_EOL.'── Las referencias del plan de área ──'.PHP_EOL;

    $cruzados = DB::selectOne(
        'SELECT COUNT(*) AS n FROM desempenos_por_defecto d
           JOIN periodos p ON p.id = d.periodo_id
          WHERE d.year_id = ? AND d.deleted_at IS NULL AND p.year_id <> ?',
        [$nuevo, $nuevo]
    );

    echo '  desempeños del año nuevo colgados de un periodo de OTRO año: '.$cruzados->n
        .($cruzados->n > 0 ? '   ← ROTO' : '   ← bien').PHP_EOL;

    $huerfanos = DB::selectOne(
        'SELECT COUNT(*) AS n FROM desempenos_por_defecto d
           LEFT JOIN competencias c ON c.id = d.competencia_id AND c.year_id = ?
          WHERE d.year_id = ? AND d.deleted_at IS NULL
            AND d.competencia_id IS NOT NULL AND c.id IS NULL',
        [$nuevo, $nuevo]
    );

    echo '  desempeños del año nuevo colgados de una competencia de OTRO año: '.$huerfanos->n
        .($huerfanos->n > 0 ? '   ← ROTO' : '   ← bien').PHP_EOL;

    foreach (DB::select(
        'SELECT d.definicion, p.numero, c.definicion AS padre
           FROM desempenos_por_defecto d
           JOIN periodos p ON p.id = d.periodo_id
           LEFT JOIN competencias c ON c.id = d.competencia_id
          WHERE d.year_id = ? AND d.deleted_at IS NULL ORDER BY d.orden', [$nuevo]
    ) as $fila) {
        echo '    · '.$fila->definicion.'  → periodo '.$fila->numero.'  · padre: '.($fila->padre ?? 'ninguno').PHP_EOL;
    }
} finally {
    DB::rollBack();
    echo PHP_EOL.'Transacción deshecha: la base de desarrollo queda como estaba.'.PHP_EOL;
    echo 'años vivos ahora: '.DB::table('years')->whereNull('deleted_at')->count()
        .' · competencias: '.DB::table('competencias')->count()
        .' · desempenos_por_defecto: '.DB::table('desempenos_por_defecto')->count().PHP_EOL;
}
