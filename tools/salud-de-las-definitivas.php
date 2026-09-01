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
 * un índice único: mientras queden duplicados, ese índice convierte cada uno en
 * un 500. O sea que este número no es informativo, es la condición de entrada.
 *
 * Por eso los bloques 1 y 2 dan **dos** números y no uno. El primero es el de la
 * tabla entera —sin `--year`, sin filtrar borrados, sin joins—, porque es lo que
 * el `ALTER TABLE` va a encontrar: un índice único no sabe de años, ni de
 * `deleted_at`, ni de si la subunidad de esa nota sigue viva. El segundo es el
 * del alcance mirado, que es el que dice **a cuántas definitivas cambia**
 * limpiar. Se separaron el 24 ago 2026, antes de correrlo en los dieciséis:
 * mezclados, la herramienta podía decir «se puede poner el índice sin limpiar
 * nada» y el índice fallar igual.
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

// ─────────────────────────────────────────────────────────────────────────────
// Las dos decisiones de este fichero que pueden equivocarse EN SILENCIO, sacadas
// del flujo para poder ejercerlas sin base.
//
// Esta herramienta no da un dato informativo: **de su número sale si la fase 2
// lleva corrección de datos**, y esa fase pone un índice único, así que cada
// duplicado que no cuente se convierte en un `ALTER TABLE` que falla — y, por lo
// aprendido la noche del 31 ago 2026, en un colegio a mitad del bucle de quince.
//
// **Ya se equivocó una vez y en esa dirección** (24 ago 2026): daba UN número
// donde hacen falta dos, y mezclados podía decir *«se puede poner el índice sin
// limpiar nada»* mientras el índice fallaba igual. Se separó el de la tabla
// entera —lo que el `ALTER` encuentra— del alcance mirado —a cuántas definitivas
// cambia limpiar—. Lo que sigue es lo que impide que se vuelvan a juntar.

/**
 * Reparte los duplicados en los tres tipos que la §9.2 distingue.
 *
 * El tipo no es descriptivo: **`manual+manual` es el único que necesita el
 * desempate por `id`**, así que este reparto es la lista de casos que alguien
 * tiene que decidir a mano. Contarlos juntos escondería cuántos son.
 *
 * @param  list<object|array<string, mixed>>  $filas
 * @return array{'auto+auto': int, 'auto+manual': int, 'manual+manual': int}
 */
function clasificarDuplicados(array $filas): array
{
    $porTipo = ['auto+auto' => 0, 'auto+manual' => 0, 'manual+manual' => 0];

    foreach ($filas as $d) {
        $d = (array) $d;
        $manuales = (int) $d['manuales'];
        $total = (int) $d['filas'];

        if ($manuales === 0) {
            $porTipo['auto+auto']++;
        } elseif ($manuales === $total) {
            $porTipo['manual+manual']++;
        } else {
            $porTipo['auto+manual']++;
        }
    }

    return $porTipo;
}

/**
 * Qué se puede afirmar sobre el `ALTER TABLE` de la fase 2, con los DOS números.
 *
 * **El que manda es el de la tabla entera, y ésa es toda la corrección del 24 ago
 * 2026 metida en una función**: el índice único no sabe de `--year`, ni de
 * `deleted_at`, ni de los dos INNER JOIN del alcance. Un `enElAlcance === 0` con
 * `enLaTabla > 0` es exactamente el caso que antes salía como «no hay que limpiar
 * nada» y reventaba la migración.
 *
 * @return array{veredicto: string, nota: string}
 */
function veredictoDelIndiceUnico(int $enLaTabla, int $enElAlcance): array
{
    if ($enLaTabla < $enElAlcance) {
        // Imposible por construcción: el alcance es un subconjunto de la tabla.
        // Si sale, las dos consultas han dejado de medir lo mismo y **ninguno de
        // los dos números vale** — que es distinto de que haya pocos duplicados.
        return [
            'veredicto' => 'incoherente',
            'nota' => "INCOHERENTE: la tabla entera dice {$enLaTabla} y el alcance mirado "
                ."{$enElAlcance}, y el alcance es un subconjunto de la tabla. Las dos "
                .'consultas han dejado de medir lo mismo: NO uses ninguno de los dos '
                .'números, y mira el detector antes que los datos.',
        ];
    }

    if ($enLaTabla === 0) {
        return [
            'veredicto' => 'se puede',
            'nota' => 'La clave única de la fase 2 se puede poner sin limpiar nada.',
        ];
    }

    $fuera = $enLaTabla - $enElAlcance;

    return [
        'veredicto' => 'hay que limpiar',
        'nota' => 'Hay que limpiarlos ANTES del índice único de la fase 2, o cada uno '
            .'de estos se convierte en un 500. Los `manual+manual` son los únicos '
            .'que necesitan el desempate por `id` de la §9.2.'
            .($fuera > 0
                ? " Y {$fuera} de ellos NO salen en el reparto por tipo "
                  .'ni en el detalle: el reparto va por el alcance mirado y el '
                  .'índice va por la tabla entera. Ésos hay que limpiarlos igual.'
                : ''),
    ];
}

/**
 * El control positivo, **sin base y sin árbol**: sólo las formas decididas.
 *
 * Lo corre `tests/Unit/AutopruebasDeLasHerramientasTest`. No puede comprobar el
 * SQL —para eso habría que fabricar filas, y esta herramienta es sólo `SELECT` a
 * propósito—, así que **ancla lo que se decide en PHP**: el reparto por tipo y el
 * veredicto del índice. Es justo donde estuvo el fallo del 24 ago.
 *
 * Y lo que este control NO promete, dicho aquí porque es la mitad honesta: **fija
 * la conducta conocida, no descubre cegueras nuevas.** Si mañana la consulta de
 * la tabla entera deja de contar una familia de filas, esto sigue verde. Para eso
 * no hay control: hay leer las filas.
 */
function controlDeSalud(): int
{
    $casos = [
        ['dos filas sin ninguna manual es auto+auto',
            [['filas' => 2, 'manuales' => 0]], ['auto+auto' => 1, 'auto+manual' => 0, 'manual+manual' => 0]],
        ['dos manuales es manual+manual: el único que pide desempate por id',
            [['filas' => 2, 'manuales' => 2]], ['auto+auto' => 0, 'auto+manual' => 0, 'manual+manual' => 1]],
        ['una de cada es auto+manual, que se resuelve solo',
            [['filas' => 2, 'manuales' => 1]], ['auto+auto' => 0, 'auto+manual' => 1, 'manual+manual' => 0]],
        // Tres filas con una manual NO es manual+manual: se decide por si TODAS
        // lo son, no por si hay alguna. Con un `>= 1` este caso entraria en la
        // lista de los que hay que decidir a mano, y esa lista saldria inflada.
        ['tres filas con UNA manual sigue siendo auto+manual',
            [['filas' => 3, 'manuales' => 1]], ['auto+auto' => 0, 'auto+manual' => 1, 'manual+manual' => 0]],
        ['tres filas todas manuales sí es manual+manual',
            [['filas' => 3, 'manuales' => 3]], ['auto+auto' => 0, 'auto+manual' => 0, 'manual+manual' => 1]],
    ];

    $fallos = [];

    foreach ($casos as [$que, $entrada, $esperado]) {
        $salio = clasificarDuplicados($entrada);
        $ok = $salio === $esperado;
        echo '  '.($ok ? 'ok  ' : 'FALLA')."  {$que}\n";
        if (! $ok) {
            $fallos[] = '    esperaba '.json_encode($esperado).' y salió '.json_encode($salio);
        }
    }

    $delIndice = [
        ['sin duplicados en la tabla, el índice entra sin limpiar', 0, 0, 'se puede'],
        // EL CASO DEL 24 AGO 2026. Con un solo número, esto decía «no hay que
        // limpiar nada» y el ALTER fallaba igual. Si algún día vuelve a dar
        // «se puede», la corrección se deshizo.
        ['CERO en el alcance mirado y TRES en la tabla: HAY QUE LIMPIAR', 3, 0, 'hay que limpiar'],
        ['los mismos en los dos sitios: hay que limpiar', 5, 5, 'hay que limpiar'],
        // El alcance es un subconjunto de la tabla: al revés es imposible, y lo
        // que hay que decir entonces es que NINGUNO de los dos números vale.
        ['la tabla con menos que el alcance es incoherente, no «pocos»', 1, 4, 'incoherente'],
    ];

    foreach ($delIndice as [$que, $tabla, $alcance, $esperado]) {
        $salio = veredictoDelIndiceUnico($tabla, $alcance)['veredicto'];
        $ok = $salio === $esperado;
        echo '  '.($ok ? 'ok  ' : 'FALLA')."  [{$esperado}] {$que}\n";
        if (! $ok) {
            $fallos[] = "    esperaba '{$esperado}' y salió '{$salio}': {$que}";
        }
    }

    $total = count($casos) + count($delIndice);
    echo "Población del control: {$total} formas comprobadas, ".count($fallos)." fallan.\n";

    if ($fallos !== []) {
        echo implode("\n", $fallos)."\n";
        echo "CONTROL FALLA: esta herramienta decide si la fase 2 lleva corrección de datos.\n"
            ."Su número NO vale hasta arreglar esto — y su forma de mentir es decir que el\n"
            ."índice único se puede poner sin limpiar nada.\n";

        return 1;
    }

    echo "OK — las nueve formas se clasifican como está decidido.\n";

    return 0;
}

if (in_array('--control', $argv ?? [], true)) {
    exit(controlDeSalud());
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ─────────────────────────────────────────────────────────────────────────────
// Una excepción aquí NO puede salir con 0, y esto es del día del despliegue.
//
// El bootstrap de Laravel pinta la excepción muy bien —con su traza y sus
// colores— y **devuelve cero**. Medido el 1 sep 2026 con `DB_DATABASE` apuntando
// a una base que no existe: la traza en pantalla y `exit=0`.
//
// Da igual mientras alguien la mire; **deja de dar igual en las quince corridas**,
// una por colegio, que es como se usa esta herramienta. Ahí el colegio cuya base
// no conteste **no se distingue del que está limpio** si el bucle mira el código
// de salida — y con `--csv` a un fichero es peor, porque la fila sale a medias y
// el CSV de los quince parece completo. **El colegio que falla es justo el que
// necesita el aviso**: puede ser el que no tiene las migraciones, o el que tiene
// el duplicado que la fase 2 no puede tragar.
//
// Sale **2** y no 1 porque no es un hallazgo ni un fallo de la herramienta: es
// que **no se pudo mirar**. Son las mismas tres salidas que ya usan
// `fase-cero-de-los-dieciseis.php` —que hace esto bien desde el principio, con su
// «un colegio NO MEDIDO no es un colegio limpio»— y el runner de autopruebas.
//
// Va por `set_exception_handler` y no envolviendo el cuerpo en un `try`: el
// cuerpo son cuatrocientas líneas lineales, y reindentarlas enteras haría
// ilegible el diff de un cambio que sólo toca el código de salida.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n!! NO MEDIDO — la base no contestó\n\n   ".$e->getMessage()."\n\n"
        ."   Esto NO es «este colegio está limpio»: es que no se ha mirado ni una fila.\n"
        ."   Si esto sale dentro del bucle de los quince, ese colegio queda SIN MEDIR y\n"
        ."   su número no está en el recuento — comprueba a qué base apunta `DB_DATABASE`\n"
        ."   y que las migraciones estén puestas ahí.\n");
    exit(2);
});

use Illuminate\Support\Facades\DB;

$opciones = getopt('', ['year::', 'detalle', 'csv', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3200), PHP_EOL;
    exit(0);
}

$soloYear = isset($opciones['year']) ? (int) $opciones['year'] : null;
$detalle = isset($opciones['detalle']);

/*
 * `--csv`: una línea por base, para juntar los dieciséis.
 *
 * Es la hermana de `salud-de-la-bitacora.php --csv`, que lo tiene desde el
 * principio, y faltaba justo aquí — o sea en la única de las dos que **desbloquea
 * una fase**: la 2 del [10] necesita estos números de los dieciséis colegios, y sin
 * CSV juntarlos es leer dieciséis informes a mano.
 *
 * **Es opt-in y no cambia ni un carácter de la salida de siempre**: quien la llame
 * sin el flag ve exactamente lo mismo que antes. Comprobado contra la salida vieja
 * byte a byte, no de vista.
 *
 * ## Y sale de los mismos bloques que el texto, a propósito
 *
 * `bloqueDeSalud()` es la única que conoce cada cifra, así que en modo CSV **la
 * misma llamada que imprimiría la línea guarda el número**. La alternativa —volver
 * a leer las variables al final y armar el CSV con ellas— sería una **segunda
 * lectura de lo mismo**, y dos lecturas de lo mismo acaban discrepando el día que
 * alguien cambie un bloque y no la otra mitad. Así no hay otra mitad.
 */
$csv = isset($opciones['csv']);

/**
 * Lo que cada bloque guardó, en orden, cuando va en CSV.
 *
 * @var list<array{titulo: string, cuantos: int, unidad: string}>
 */
$bloquesParaCsv = [];

$base = DB::connection()->getDatabaseName();
$filtroYear = $soloYear !== null ? ' AND g.year_id = '.$soloYear : '';

if (! $csv) {
    echo PHP_EOL;
    echo "Salud de `notas_finales` — base `{$base}`";
    echo $soloYear !== null ? ", año {$soloYear}" : ', todos los años';
    echo PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;
}

/**
 * Imprime un bloque con su título, su número y —si se pidió— sus primeras filas.
 *
 * El nombre lleva sufijo porque `tools/auditar-autenticacion.php` ya define una
 * `bloque()` global con otra firma. Los dos son scripts sueltos y nunca se cargan
 * juntos, así que hoy no choca; lo dijo larastan, que sí los analiza a la vez, y
 * se le hace caso porque el día que alguien incluya uno desde el otro el fatal es
 * inmediato.
 */
function bloqueDeSalud(string $titulo, int $cuantos, string $unidad, array $ejemplos = [], string $nota = ''): void
{
    global $detalle, $csv, $bloquesParaCsv;

    if ($csv) {
        $bloquesParaCsv[] = ['titulo' => $titulo, 'cuantos' => $cuantos, 'unidad' => $unidad];

        return;
    }

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

$porTipo = clasificarDuplicados($duplicados);

// Y aparte, lo que el `ALTER TABLE` va a rechazar de verdad — que **no** es el
// conteo de arriba. Aquélla lleva `--year` y dos INNER JOIN, y un índice único
// no sabe de años ni de joins: es de la tabla entera. Además
// `asignaturas.grupo_id` **no tiene clave foránea** —está así en el volcado—,
// así que una asignatura cuyo grupo ya no exista se cae del INNER JOIN y sus
// duplicados no se cuentan, pero el índice sí los encuentra. Aquí no pasa (0
// asignaturas huérfanas el 24 ago), y ése es justo el motivo de medirlo en los
// dieciséis en vez de suponerlo.
//
// Las filas con alguna clave a NULL se excluyen porque MySQL admite NULL
// repetido en un índice único: no bloquean nada.
$duplicadosTabla = (int) DB::selectOne(
    'SELECT COUNT(*) AS n FROM (
        SELECT alumno_id, asignatura_id, periodo_id
          FROM notas_finales
         WHERE alumno_id IS NOT NULL
           AND asignatura_id IS NOT NULL
           AND periodo_id IS NOT NULL
         GROUP BY alumno_id, asignatura_id, periodo_id
        HAVING COUNT(*) > 1
     ) t'
)->n;

bloqueDeSalud(
    '1. Definitivas duplicadas — (alumno, asignatura, periodo) con más de una fila',
    $duplicadosTabla,
    'combinaciones que el índice único rechazaría, en la tabla entera · '.
        'en el alcance mirado: '.count($duplicados).' · '.
        "auto+auto: {$porTipo['auto+auto']} · ".
        "auto+manual: {$porTipo['auto+manual']} · ".
        "manual+manual: {$porTipo['manual+manual']}",
    array_map(fn ($d) => (array) $d, $duplicados),
    // La nota sale de `veredictoDelIndiceUnico()` y no se escribe aquí, para que
    // el control esté ejerciendo **esta** frase y no una copia suya. Una nota
    // duplicada a mano es cómo el arreglo del 24 ago se deshace sin que nadie lo
    // note: el control seguiría verde sobre una función que ya no se usa.
    veredictoDelIndiceUnico($duplicadosTabla, count($duplicados))['nota']
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

// Lo mismo que en el bloque 1, y aquí el hueco es mayor: la consulta de arriba
// pide `n.deleted_at IS NULL` y que la subunidad y la unidad estén vivas, y el
// índice único no pide nada de eso. `notas` usa SoftDeletes —el modelo lo
// declara—, o sea que una fila borrada sigue en la tabla y sigue chocando; y
// bajo subunidades borradas hay **35.796 notas** sólo en esta base. Que aquí los
// dos números coincidan es suerte de esta base, no una propiedad del esquema.
//
// `subunidad_id` y `alumno_id` son NOT NULL en el volcado, así que no hay filas
// que escapen por NULL como en el bloque 1.
$notasDupTabla = (int) DB::selectOne(
    'SELECT COUNT(*) AS n FROM (
        SELECT subunidad_id, alumno_id
          FROM notas
         GROUP BY subunidad_id, alumno_id
        HAVING COUNT(*) > 1
     ) t'
)->n;

$notasFueraDelAlcance = $notasDupTabla - count($notasDup);

bloqueDeSalud(
    '2. Notas duplicadas — (subunidad, alumno) con más de una nota',
    $notasDupTabla,
    'combinaciones que el índice único rechazaría, en la tabla entera · '.
        'vivas y bajo estructura viva: '.count($notasDup).
        " · con valores distintos entre sí: {$discrepan}",
    array_map(fn ($n) => (array) $n, $notasDup),
    $notasDupTabla === 0
        ? 'La clave única de la fase 2 se puede poner sin limpiar nada.'
        : 'Las que tienen valores distintos son las que cambian la definitiva al '.
          'limpiarlas: hoy las dos cuentan en la suma. Gana la más alta (§9.2).'.
          ($notasFueraDelAlcance > 0
              ? " Las otras {$notasFueraDelAlcance} están borradas o cuelgan de ".
                'una subunidad borrada: no cambian ninguna definitiva, pero '.
                'bloquean el índice igual y hay que limpiarlas también.'
              : '')
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

bloqueDeSalud(
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

bloqueDeSalud(
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

bloqueDeSalud(
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

bloqueDeSalud(
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

bloqueDeSalud(
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

bloqueDeSalud(
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

if (! $csv) {
    echo str_repeat('=', 78).PHP_EOL;
    echo 'Sin --detalle solo se ven los conteos. Con él salen las primeras filas de'.PHP_EOL;
    echo 'cada bloque, que es lo que hace falta para ir a mirar un caso de verdad.'.PHP_EOL.PHP_EOL;
    echo 'Con --csv sale una línea por base, para juntar los dieciséis en una tabla.'.PHP_EOL.PHP_EOL;
}

// ---------------------------------------------------------------------------
// Salida CSV: una línea por base, para juntar los dieciséis.
// ---------------------------------------------------------------------------
if ($csv) {
    /*
     * Título de bloque -> nombre de columna. Es una lista a mano, así que lleva
     * guardián: si aparece un bloque nuevo sin entrada aquí, esto **falla y lo
     * dice** en vez de imprimir una fila a la que le falta una columna.
     *
     * Y ese modo de fallo es el que hay que evitar sobre dieciséis bases: dieciséis
     * filas con una columna de menos no se ven raras — se ven completas.
     */
    $columnas = [
        '1. Definitivas duplicadas' => 'definitivas_duplicadas',
        '2. Notas duplicadas' => 'notas_duplicadas',
        '3. Definitivas contra el cálculo' => 'definitivas_que_faltan',
        '3.1 De las que sí existen' => 'definitivas_que_discrepan',
        '4. `periodo` que no concuerda' => 'periodo_descuadrado',
        '5. `created_at` imposible' => 'created_at_imposible',
        '6. Porcentajes que no suman' => 'porcentajes_malos',
        '7. Subunidades vivas sin nota' => 'subunidades_sin_nota',
    ];

    $campos = ['base' => $base, 'year' => $soloYear ?? 'todos'];
    $sinColumna = [];

    foreach ($bloquesParaCsv as $b) {
        $clave = null;

        foreach ($columnas as $prefijo => $nombre) {
            if (str_starts_with($b['titulo'], $prefijo)) {
                $clave = $nombre;
                break;
            }
        }

        if ($clave === null) {
            $sinColumna[] = $b['titulo'];

            continue;
        }

        $campos[$clave] = $b['cuantos'];
    }

    if ($sinColumna !== []) {
        fwrite(STDERR, 'ABORTO: hay bloques sin columna en el CSV, así que la fila saldría'.PHP_EOL);
        fwrite(STDERR, 'incompleta y parecería completa. Añádeles su nombre en $columnas:'.PHP_EOL);

        foreach ($sinColumna as $t) {
            fwrite(STDERR, '  - '.$t.PHP_EOL);
        }

        exit(1);
    }

    /*
     * Y la comprobación contraria: que no falte ninguna columna esperada. Un
     * bloque que dejara de ejecutarse —porque alguien lo comentó, o porque su
     * consulta murió— haría desaparecer su columna, y entonces las dieciséis filas
     * no tendrían la misma forma. Con esto, falta un bloque y se sabe.
     */
    $faltan = array_diff(array_values($columnas), array_keys($campos));

    if ($faltan !== []) {
        fwrite(STDERR, 'ABORTO: estos bloques no se ejecutaron, así que su columna no existe: '
            .implode(', ', $faltan).PHP_EOL);
        exit(1);
    }

    echo implode(',', array_keys($campos)).PHP_EOL;
    echo implode(',', array_map(static fn ($v): string => (string) $v, $campos)).PHP_EOL;
}
