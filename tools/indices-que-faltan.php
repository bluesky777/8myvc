<?php

/**
 * Qué consultas de las que ejecuta la suite no pueden usar ningún índice.
 *
 * Es el paso 12 del plan de rendimiento hecho por donde el plan manda, que es
 * midiendo: «no adivines. Añadir índices a ciegas en una tabla de 1,16 millones
 * de filas ralentiza las escrituras y ocupa disco sin garantía de ganancia».
 * Aquí no se adivina nada — lo dice el EXPLAIN de MySQL.
 *
 * Uso (dentro del contenedor, contra la base de tests):
 *
 *   docker exec -e EXPLICAR_CONSULTAS=/tmp/consultas.jsonl 8myvc-app-1 \
 *       php artisan test --testsuite=Contrato
 *   docker exec 8myvc-app-1 php tools/indices-que-faltan.php /tmp/consultas.jsonl
 *
 * El capturador vive en tests/TestCase.php y solo se enciende con la variable.
 *
 * **Por qué vale medirlo contra el seed y no contra producción.** Lo que se
 * busca es `possible_keys` vacío: que para esa consulta NO EXISTA un índice que
 * MySQL pudiera considerar. Eso es una propiedad del esquema, no del volumen —
 * el optimizador lista los índices candidatos mirando el WHERE, antes de contar
 * filas. Con el seed pequeño se ve el mismo hecho que en un colegio con un
 * millón de notas; lo que cambia es cuánto cuesta, no si el índice existe.
 *
 * Lo que esto NO puede decir es cuáles de esos merecen el índice. Para eso hace
 * falta el paso 3 —el registro de consultas lentas, una temporada en
 * producción— y `tools/consultas-lentas.py`. Esta lista es la de candidatos;
 * aquella dice cuáles se llevan el tiempo de verdad.
 *
 * **Y el hueco que sí conviene tener en la cabeza, porque no se ve:** esto solo
 * mira las consultas que **la suite ejecuta**. Una ruta sin ningún test no pasa
 * por aquí, así que sus consultas no están en la lista — y no estar en la lista
 * se lee como «no tiene problema». El 21 ago 2026 había **194 rutas sin
 * comprobar** (`tools/cobertura-de-rutas.py`), o sea que el 36% de la API está
 * fuera de esta medición.
 *
 * Ejemplo medido ese día, para que no quede en abstracto:
 * `GET api/ChangesAsked/to-me` —la pantalla de inicio del superusuario y del
 * profesor— hace
 *
 *     SELECT * FROM bitacoras
 *     WHERE affected_element_type="intento_login" AND affected_person_name=?
 *       AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 50
 *
 * y `bitacoras` solo tiene índice por `id` y por `historial_id`: el EXPLAIN da
 * `type=ALL, possible_keys=NULL`. Es un candidato de manual y **nunca ha salido
 * aquí**, porque ningún test golpea esa ruta. Cuánto cuesta depende de cuántas
 * filas tenga `bitacoras` en cada colegio —en la copia de desarrollo son 59 y no
 * cuesta nada, pero ahí se escribe un intento de login fallido por cada uno—, así
 * que sigue haciendo falta el paso 3 antes de crear nada. Lo que no hace falta
 * medir es el hueco: las dos series —cobertura e índices— se tapan una a la otra
 * y conviene correrlas sabiéndolo.
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fichero = $argv[1] ?? null;
$conexion = $argv[2] ?? 'mysql_testing';

if (! $fichero || ! is_readable($fichero)) {
    fwrite(STDERR, "Falta el fichero de consultas.\n\n".trim(substr(file_get_contents(__FILE__), 0, 1900))."\n");
    exit(1);
}

$db = DB::connection($conexion);
$base = $db->getDatabaseName();

echo "Base: {$base}\n";

/** Filas de cada tabla, para poder ordenar por lo que duele. */
$filasPorTabla = [];
foreach ($db->select('SELECT table_name AS t, table_rows AS n FROM information_schema.tables WHERE table_schema = ?', [$base]) as $fila) {
    $filasPorTabla[$fila->t] = (int) $fila->n;
}

$consultas = [];
$lineas = file($fichero);

if ($lineas === false) {
    fwrite(STDERR, "No se pudo leer $fichero\n");
    exit(1);
}

foreach ($lineas as $linea) {
    $linea = trim($linea);
    if ($linea === '') {
        continue;
    }
    $registro = json_decode($linea, true);
    if (! is_array($registro) || ! isset($registro['sql'])) {
        continue;
    }
    // Entre procesos la misma consulta se repite: la deduplicación de
    // tests/TestCase.php es por proceso, y `--parallel` los reparte.
    $consultas[md5($registro['sql'])] = $registro;
}

echo count($consultas)." consultas distintas capturadas.\n\n";

$hallazgos = [];   // tabla -> lista de consultas que la recorren sin índice posible
$saltadas = 0;

foreach ($consultas as $registro) {
    $sql = trim($registro['sql']);

    // Las escrituras de los propios tests (transacciones, seeds) no interesan;
    // sí las lecturas y los UPDATE/DELETE con WHERE, que también escanean.
    if (! preg_match('/^(select|update|delete)\b/i', $sql)) {
        continue;
    }

    try {
        $plan = $db->select('EXPLAIN '.$sql, $registro['valores'] ?? []);
    } catch (\Throwable $e) {
        // Una consulta rota no es cosa de esta herramienta: las tiene contadas
        // docs/migracion/05-codigo-muerto-y-roto.md.
        $saltadas++;

        continue;
    }

    foreach ($plan as $paso) {
        $tabla = $paso->table ?? null;

        // Sin tabla (subconsultas materializadas, UNION RESULT) o con índice
        // posible: no es lo que se busca.
        if (! $tabla || str_starts_with($tabla, '<')) {
            continue;
        }

        // EXPLAIN devuelve el ALIAS, no el nombre de la tabla, y aquí casi
        // todas las consultas lo usan (`from years y`). Sin deshacerlo, el
        // informe pregunta por los índices de una tabla llamada `y`.
        $tabla = tablaReal($sql, $tabla, $filasPorTabla);

        if ($tabla === null) {
            continue;
        }

        if (! empty($paso->possible_keys)) {
            continue;
        }

        // Recorrerse entera una tabla que no se filtra por nada —un catálogo
        // leído completo— no es un índice que falte: no hay nada que indexar.
        $columnas = columnasFiltradas($sql, $tabla);

        if (! $columnas) {
            continue;
        }

        $hallazgos[$tabla][] = [
            'sql' => $sql,
            'origen' => $registro['origen'] ?? null,
            'columnas' => $columnas,
            'tipo' => $paso->type ?? '?',
            'filas' => (int) ($paso->rows ?? 0),
        ];
    }
}

/**
 * De qué tabla habla EXPLAIN cuando dice `y`.
 *
 * La columna `table` del plan trae el alias, y en este proyecto casi ninguna
 * consulta escribe la tabla dos veces: `from years y`, `inner join periodos p`.
 */
function tablaReal(string $sql, string $alias, array $tablasConocidas): ?string
{
    if (isset($tablasConocidas[$alias])) {
        return $alias;
    }

    $plano = preg_replace('/\s+/', ' ', $sql);

    if (preg_match('/\b(?:from|join|update)\s+`?(\w+)`?\s+(?:as\s+)?`?'.preg_quote($alias, '/').'`?\b/i', $plano, $m)) {
        return isset($tablasConocidas[$m[1]]) ? $m[1] : null;
    }

    return null;
}

/**
 * Por qué columnas de esa tabla filtra la consulta.
 *
 * Es lo que convierte «esta tabla se recorre entera» en algo accionable: la
 * tabla no dice qué índice crear, la columna sí.
 *
 * Best-effort a propósito: se buscan comparaciones `alias.columna = …` dentro
 * del texto de la consulta. Lo que no case se queda fuera, que es el error
 * barato — esto produce candidatos para mirar con la lista de consultas lentas
 * al lado, no migraciones automáticas.
 *
 * **`deleted_at` no cuenta, y descartarlo es la mitad del valor de esto.** Las
 * 990 consultas de este proyecto llevan casi todas un `deleted_at is null`
 * escrito a mano, y sin descartarlo aquí salen las 90 tablas: un índice sobre
 * una columna que es NULL en el 99% de las filas no lo usaría MySQL ni aunque
 * existiera. Lo mismo con las fechas de auditoría.
 */
function columnasFiltradas(string $sql, string $tabla): array
{
    $NO_CUENTAN = ['deleted_at', 'created_at', 'updated_at'];

    $plano = preg_replace('/\s+/', ' ', $sql);

    // Con qué nombres se refiere la consulta a esta tabla: `from images i`,
    // `join images as i`, o `images` a secas.
    $alias = [$tabla];
    if (preg_match_all('/\b(?:from|join|update)\s+`?'.preg_quote($tabla, '/').'`?\s+(?:as\s+)?`?(\w+)`?/i', $plano, $m)) {
        foreach ($m[1] as $a) {
            if (! in_array(strtolower($a), ['on', 'where', 'inner', 'left', 'right', 'join', 'set', 'group', 'order', 'limit'], true)) {
                $alias[] = $a;
            }
        }
    }

    $columnas = [];

    foreach ($alias as $a) {
        // `i.user_id = ?`, `i`.`user_id` IN (...), etc.
        if (preg_match_all('/\b'.preg_quote($a, '/').'`?\.`?(\w+)`?\s*(?:=|<>|!=|<|>|\bin\b|\blike\b)/i', $plano, $m)) {
            $columnas = array_merge($columnas, $m[1]);
        }
    }

    // Una sola tabla y sin JOIN: `where user_id = ?` ya es filtrar por ella,
    // aunque nadie haya escrito el alias delante.
    $unaSola = substr_count(strtolower($plano), ' join ') === 0;

    if ($unaSola && preg_match('/\bwhere\b(.*)$/i', $plano, $m)) {
        if (preg_match_all('/`?(\w+)`?\s*(?:=|<>|!=|<|>|\bin\b|\blike\b)/i', $m[1], $mm)) {
            $columnas = array_merge($columnas, $mm[1]);
        }
    }

    $columnas = array_values(array_unique(array_map('strtolower', $columnas)));

    return array_values(array_diff($columnas, $NO_CUENTAN));
}

if (! $hallazgos) {
    echo "Ninguna consulta de la suite recorre una tabla sin índice posible.\n";
    exit(0);
}

// Por filas de la tabla: es donde un escaneo cuesta de verdad.
uksort($hallazgos, fn ($a, $b) => ($filasPorTabla[$b] ?? 0) <=> ($filasPorTabla[$a] ?? 0));

echo str_repeat('=', 78)."\n";
echo "Columnas por las que se filtra sin que exista un índice que las cubra\n";
echo str_repeat('=', 78)."\n\n";

$candidatos = 0;

foreach ($hallazgos as $tabla => $casos) {
    $indices = indicesDe($db, $tabla);
    $cubiertas = primerasColumnas($db, $tabla);
    $existen = columnasDe($db, $tabla);

    // Solo las columnas que existen de verdad y que ningún índice encabeza. Que
    // una columna vaya en SEGUNDA posición de un índice compuesto no la cubre:
    // MySQL solo usa el prefijo por la izquierda.
    $porColumna = [];

    foreach ($casos as $caso) {
        foreach ($caso['columnas'] as $columna) {
            if (! in_array($columna, $existen, true) || in_array($columna, $cubiertas, true)) {
                continue;
            }
            $porColumna[$columna][] = $caso;
        }
    }

    if (! $porColumna) {
        continue;
    }

    $candidatos++;
    $filas = $filasPorTabla[$tabla] ?? 0;

    echo "### {$tabla}  ({$filas} filas en la base de tests)\n";
    echo '    índices hoy: '.implode(', ', $indices)."\n\n";

    arsort($porColumna);

    foreach ($porColumna as $columna => $suyos) {
        echo '    · '.$tabla.'.'.$columna.'  — '.count($suyos)." consulta(s) de la suite filtran por aquí\n";

        $origenes = array_values(array_unique(array_filter(array_column($suyos, 'origen'))));
        if ($origenes) {
            echo '      rutas: '.implode(', ', array_slice($origenes, 0, 5)).(count($origenes) > 5 ? ', …' : '')."\n";
        }

        echo '      '.substr(preg_replace('/\s+/', ' ', $suyos[0]['sql']), 0, 200)."\n\n";
    }
}

if (! $candidatos) {
    echo "Ninguna columna filtrada se queda sin índice.\n";
}

if ($saltadas) {
    echo "({$saltadas} consultas no se pudieron explicar; suelen ser las rotas de 05-codigo-muerto-y-roto.md)\n";
}

echo "\nLo que esto NO dice es cuáles merecen el índice: para eso hace falta el\n";
echo "registro de consultas lentas de producción (paso 3) y tools/consultas-lentas.py.\n";

/** Las columnas que encabezan algún índice: las únicas que MySQL puede usar sola. */
function primerasColumnas($db, string $tabla): array
{
    $primeras = [];

    foreach ($db->select('SHOW INDEX FROM `'.$tabla.'`') as $fila) {
        if ((int) $fila->Seq_in_index === 1) {
            $primeras[] = strtolower($fila->Column_name);
        }
    }

    return $primeras;
}

function columnasDe($db, string $tabla): array
{
    return array_map('strtolower', $db->getSchemaBuilder()->getColumnListing($tabla));
}

function indicesDe($db, string $tabla): array
{
    $indices = [];

    foreach ($db->select('SHOW INDEX FROM `'.$tabla.'`') as $fila) {
        $indices[$fila->Key_name][(int) $fila->Seq_in_index] = $fila->Column_name;
    }

    $salida = [];
    foreach ($indices as $nombre => $columnas) {
        ksort($columnas);
        $salida[] = $nombre.'('.implode(',', $columnas).')';
    }

    return $salida ?: ['ninguno más que la clave primaria, si la tiene'];
}
