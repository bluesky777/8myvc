<?php

/**
 * Escribe las columnas reales de cada tabla en el docblock de su modelo.
 *
 * **Existe por una consecuencia de la Fase 5.** El esquema de este proyecto no
 * está en migraciones —90 tablas contra 3 ficheros—, sino congelado en
 * `database/schema/mysql-schema.sql`. Larastan no puede leer eso, así que para
 * el análisis un modelo Eloquent no tiene ninguna columna: al subir al nivel 2
 * salen 144 «propiedad no definida» que son todas columnas que existen.
 *
 * La lista NO se escribe a mano. Se genera desde el esquema real, que es la
 * misma decisión que ya se tomó en `App\Support\ColumnaSegura`: una lista a mano
 * se queda corta el día que alguien añada un campo, y entonces el análisis
 * empieza a mentir en la dirección peligrosa.
 *
 * Uso:
 *   php tools/columnas-en-los-modelos.php            # dice qué haría
 *   php tools/columnas-en-los-modelos.php --escribir # lo escribe
 *   php tools/columnas-en-los-modelos.php --control  # su control positivo, sin base ni árbol
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ MUEVE EN VEZ DE REEMPLAZAR, que es lo único que cambió el 4 sep 2026
 *
 * Esta cabecera decía que la herramienta «no pisa nada escrito por una persona»,
 * y era verdad **fuera** de las marcas y mentira dentro. Dentro, el bloque se
 * reemplazaba entero desde el volcado — y **el volcado está congelado**: una
 * columna que entra por migración no está ahí. Quien la anota a mano la escribe
 * donde están todas las demás, o sea DENTRO, y la siguiente corrida se la lleva.
 *
 * Medido el 4 sep 2026 sobre `0345ad5`, regenerando los 54 ficheros de modelo
 * sobre copias en `/tmp` y diffeando `@property` a `@property`:
 *
 *     54 ficheros mirados (53 en app/Models + app/User.php)
 *     47 con bloque generado · 7 saltados por no encontrar su tabla
 *     760 @property dentro de las marcas · 77 fuera (intactas)
 *     2 se PERDÍAN · 0 entraban
 *       Subunidad.rubrica_id  (2026_09_03_100000_rubricas)
 *       Profesor.tono         (2026_09_04_200000_tono_del_docente)
 *
 * Sobre `main` (`8f59242`) la cuenta era 1: la de `Subunidad`. La de `Profesor`
 * entra con la rama del horario.
 *
 * **Y falla en la dirección que no se nota.** Borrar una `@property` no pone nada
 * rojo: ninguna suite ejecuta `tools/`, y larastan simplemente deja de saber que
 * la columna existe. El daño sale semanas después como un error de nivel 7 que
 * alguien «arregla» volviendo a anotarla — dentro del bloque, para que la próxima
 * corrida la borre otra vez.
 *
 * Lo que hace ahora: una `@property` de dentro de las marcas cuyo nombre **no es
 * una columna de la tabla** no se borra, **se mueve justo debajo de la marca de
 * fin**, literal y con su comentario, y se dice en pantalla. Después de una
 * corrida, dentro de las marcas sólo hay generado y fuera sólo hay mano — que es
 * lo que esta cabecera afirmaba y ahora es cierto.
 *
 * **Y por qué mover y no fusionar**, que era la otra salida y parecía más corta:
 * fusionar deja lo escrito a mano DENTRO del bloque, o sea convierte «esto lo
 * genera una herramienta» en «esto lo genera una herramienta y a veces no», y
 * entonces nadie puede mirar el bloque y saber qué es qué. Moviendo, el sitio
 * donde una persona escribe sigue siendo el de siempre —debajo— y es el que
 * `Year.php` **ya usaba**: sus dos columnas por migración (`regla_nivelacion`,
 * `horario_version_id`) llevan ahí desde que entraron, con su prosa al lado, y
 * nunca corrieron peligro. Las dos que se perdían son las de los dos modelos que
 * las metieron dentro. El convenio no es nuevo: esto lo hace cumplir solo.
 *
 * **Lo que NO arregla, y no es un olvido:** un comentario a mano pegado a una
 * línea que SÍ es columna se sigue perdiendo, porque esa línea se regenera; no se
 * puede conservar sin volver a abrir el bloque a la mano. Se **avisa** en
 * pantalla y se cuenta aparte, que es la diferencia entre perder algo y perderlo
 * en silencio. El 4 sep 2026 había 1 de 760, y era la misma de `Subunidad`, que
 * se va entera al moverse.
 *
 * **Y lo que no se decide aquí:** el volcado sigue sin las columnas nuevas, así
 * que estas anotaciones se seguirán escribiendo a mano. Refrescarlo es cambiar
 * «la verdad» del esquema y es decisión de Joseth. Leer el esquema **vivo** en su
 * lugar está descartado y medido: no es el de ningún colegio. El 4 sep 2026,
 * `years` daba **64** columnas en el volcado y **70** en `simonbolivar_testing_a`
 * ya migrada — anotar desde la base de desarrollo metería en los dieciséis
 * colegios columnas que allí no existen, que es la misma dirección peligrosa por
 * el otro lado.
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Str;

const MARCA_INICIO = ' * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---';
const MARCA_FIN = ' * --- fin de las columnas generadas ---';

/**
 * La cabecera de lo que se mueve fuera del bloque.
 *
 * Es una marca y no prosa suelta porque hace falta poder **volver a encontrarla**:
 * la segunda corrida que mueva algo lo cuelga aquí en vez de abrir otra sección.
 */
const NOTA_FUERA = " * --- y las que no salen del volcado: las movió aquí tools/columnas-en-los-modelos.php ---\n".
    " *\n".
    " * Entran por migración, así que el esquema congelado no las tiene y esta\n".
    " * herramienta no puede generarlas. Estaban DENTRO de las marcas, que es donde\n".
    " * la siguiente corrida las habría borrado sin poner nada rojo. Aquí no se tocan.\n".
    ' *';

/**
 * El tipo de PHP que va a devolver Eloquent para esa columna de MySQL.
 *
 * En MySQL una columna admite NULL salvo que diga lo contrario, así que la
 * pregunta es al revés: nulable es lo normal, `NOT NULL` es la excepción.
 */
function tipoPhp(string $tipoSql, bool $obligatoria): string
{
    $tipoSql = strtolower($tipoSql);
    $prefijo = $obligatoria ? '' : '?';

    if (str_contains($tipoSql, 'int')) {
        return $prefijo.'int';
    }
    if (in_array($tipoSql, ['decimal', 'float', 'double'], true)) {
        return $prefijo.'float';
    }

    return $prefijo.'string';
}

/**
 * La tabla de un modelo: la que declara, o la que sale de la convención.
 *
 * No se adivina: si el nombre deducido no existe en el esquema, se salta y se
 * dice. Un modelo anotado con las columnas de otra tabla sería peor que uno sin
 * anotar.
 *
 * @param  array<string, array<string, string>>  $columnasPorTabla
 */
function tablaDe(string $fuente, string $clase, array $columnasPorTabla): ?string
{
    if (preg_match('/protected\s+\$table\s*=\s*[\'"](\w+)[\'"]/', $fuente, $m)) {
        return isset($columnasPorTabla[$m[1]]) ? $m[1] : null;
    }

    $candidatos = [
        Str::snake(Str::pluralStudly($clase)),
        Str::snake($clase),
    ];

    foreach ($candidatos as $candidato) {
        if (isset($columnasPorTabla[$candidato])) {
            return $candidato;
        }
    }

    return null;
}

/** Los nombres de `@property` que hay en un texto, sin mirar tipo ni comentario. */
function nombresDePropiedad(string $texto): array
{
    preg_match_all('/^ \* @property\s+\S+\s+\$(\w+)/m', $texto, $m);

    return $m[1];
}

/**
 * El fichero con su bloque al día, lo que hubo que mover y lo que se pierde igual.
 *
 * Devuelve las tres cosas y no sólo la fuente **porque las dos listas son el
 * resultado**: una herramienta que arregla en silencio lo que otra rompía en
 * silencio deja el mismo agujero, sólo que tapado.
 *
 * @param  array<string, string>  $columnas
 * @return array{fuente: string, movidas: array<string, string>, comentarios: array<string, string>}
 */
function reescribir(string $fuente, string $clase, string $tabla, array $columnas): array
{
    $movidas = [];
    $comentarios = [];
    $entreMarcas = '/'.preg_quote(MARCA_INICIO, '/').'.*?'.preg_quote(MARCA_FIN, '/').'/s';

    if (preg_match($entreMarcas, $fuente, $m)) {
        // Lo que ya vive fuera no se duplica: es el caso de `Year.php`, que las
        // puso debajo desde el principio y por eso nunca perdió ninguna.
        $fuera = nombresDePropiedad((string) preg_replace($entreMarcas, '', $fuente));

        preg_match_all('/^ \* (@property\s+\S+\s+\$(\w+)(.*))$/m', $m[0], $viejas, PREG_SET_ORDER);

        foreach ($viejas as [, $linea, $nombre, $resto]) {
            if (! isset($columnas[$nombre])) {
                // No sale del volcado, así que la escribió una persona. Se mueve.
                if (! in_array($nombre, $fuera, true)) {
                    $movidas[$nombre] = ' * '.rtrim($linea);
                }

                continue;
            }

            // Sí es columna: la línea se regenera y el texto pegado se pierde. No
            // se puede conservar sin reabrir el bloque a la mano, así que se avisa.
            if (trim($resto) !== '') {
                $comentarios[$nombre] = trim($resto);
            }
        }
    }

    $lineas = [MARCA_INICIO, ' *'];

    foreach ($columnas as $columna => $tipo) {
        $lineas[] = ' * @property '.$tipo.' $'.$columna;
    }

    $lineas[] = MARCA_FIN;
    $bloque = implode("\n", $lineas);

    // Ya estaba: se reemplaza entre marcas y no se toca nada más.
    if (str_contains($fuente, MARCA_INICIO)) {
        $nuevo = (string) preg_replace($entreMarcas, $bloque, $fuente);

        return ['fuente' => conLasMovidas($nuevo, $movidas), 'movidas' => $movidas, 'comentarios' => $comentarios];
    }

    $declaracion = '/^(\s*(?:final\s+|abstract\s+)?class\s+'.preg_quote($clase, '/').'\b)/m';

    if (! preg_match($declaracion, $fuente)) {
        return ['fuente' => $fuente, 'movidas' => [], 'comentarios' => []];
    }

    $docblock = "/**\n * Las columnas de `{$tabla}`, tal como están en el esquema congelado.\n *\n".
        " * Generado desde database/schema/mysql-schema.sql — no se edita a mano.\n * Ver tools/columnas-en-los-modelos.php.\n *\n".
        $bloque."\n */\n";

    return [
        'fuente' => (string) preg_replace($declaracion, $docblock.'$1', $fuente, 1),
        'movidas' => [],
        'comentarios' => [],
    ];
}

/**
 * Cuelga debajo de la marca de fin lo que no salía del volcado.
 *
 * @param  array<string, string>  $movidas
 */
function conLasMovidas(string $fuente, array $movidas): string
{
    if ($movidas === []) {
        return $fuente;
    }

    $lineas = implode("\n", array_values($movidas));

    // Si la sección ya existe se cuelga de ella; abrir una segunda dejaría el
    // docblock diciendo dos veces lo mismo y sin orden de lectura.
    if (str_contains($fuente, NOTA_FUERA)) {
        return str_replace(NOTA_FUERA, NOTA_FUERA."\n".$lineas, $fuente);
    }

    return str_replace(MARCA_FIN, MARCA_FIN."\n *\n".NOTA_FUERA."\n".$lineas, $fuente);
}

// ─────────────────────────────────────────────────────────────────────────────
// El control positivo, ejecutable y **antes de leer nada**: no toca la base, no
// mira el árbol y no llama a `git`. Sus entradas son las seis formas de abajo.
//
// Lo corre `tests/Unit/AutopruebasDeLasHerramientasTest`. Tres salidas y no dos,
// como manda ese runner: 0 pasa, 1 la herramienta cambió de opinión, 2 no concluye.
//
// **Y fija las formas, no un número del árbol.** El número de `@property` que hoy
// se perderían cambia cada vez que alguien migra una columna; un control anclado
// en él se reescribe con el que salga en vez de con el que debía salir.
//
// ─────────────────────────────────────────────────────────────────────────────
// POR QUÉ SE LLAMAN `formasDeControl` Y `controlDeLasColumnas` Y NO LO OBVIO
//
// Los ficheros de `tools/` son scripts sueltos **sin namespace**, así que todas
// sus funciones caen en el global. En ejecución da igual —cada uno corre en su
// propio proceso—, pero **larastan analiza la carpeta entera como un proyecto** y
// ahí sí colisionan. Este control se escribió el 4 sep 2026 con los nombres
// naturales, `casosDeControl()` y `control()`, que son los que ya usa
// `independientes-sin-estructura.php`, y larastan resolvió la llamada **contra la
// función del otro fichero**: `callable.nonCallable`, «Trying to invoke
// array<string, mixed>» — que es la firma del OTRO `casosDeControl`, no la de
// éste. La anotación de aquí era correcta y no se estaba usando.
//
// Se cuenta porque el modo de fallar es el de siempre en esta carpeta: **en
// ejecución no pasa nada** y el único que mira aquí es larastan. Con `control()`
// —los dos devuelven `int`— no salió ningún error, así que la colisión que no
// cambia de tipo **no se delata**: se renombran las dos, no sólo la que cantó.

/** Un modelo de mentira con el bloque que se le pida. */
function modeloDeControl(string $dentro, string $debajo = ''): string
{
    return "<?php namespace App\Models;\n\n/**\n * Las columnas de `cosas`.\n *\n"
        .MARCA_INICIO."\n *\n".$dentro."\n".MARCA_FIN."\n"
        .($debajo === '' ? '' : " *\n".$debajo."\n")
        ." */\nclass Cosa extends Model {}\n";
}

/** @return array<string, string> */
function columnasDeControl(): array
{
    return ['id' => 'int', 'nombre' => '?string'];
}

/**
 * Las seis formas, con lo que tiene que pasarle a cada una.
 *
 * Cada forma trae su comprobación dentro y devuelve **qué salió mal**, cadena vacía
 * si nada: así el motivo se escribe pegado al caso y no en una tabla de esperados
 * aparte, que es donde se desincronizan.
 *
 * @return list<array{0: string, 1: callable(): string}>
 */
function formasDeControl(): array
{
    $cols = columnasDeControl();
    $aMano = ' * @property ?int $rubrica_id  ← a mano: la añade una migración y no está en el volcado';

    return [
        // La forma que se perdía de verdad, y por la que existe todo esto.
        ['una @property de dentro que NO es columna se mueve fuera, y NO se borra',
            function () use ($cols, $aMano): string {
                $r = reescribir(modeloDeControl(" * @property int \$id\n".$aMano), 'Cosa', 'cosas', $cols);
                $bloque = entreMarcas($r['fuente']);

                if (! str_contains($r['fuente'], 'rubrica_id')) {
                    return 'se ha BORRADO: no aparece en el fichero resultante';
                }
                if (str_contains($bloque, 'rubrica_id')) {
                    return 'sigue dentro de las marcas, o sea la próxima corrida la borra';
                }
                if (! str_contains($r['fuente'], 'la añade una migración')) {
                    return 'se movió sin su comentario, que es la mitad que dice de dónde salió';
                }

                return array_keys($r['movidas']) === ['rubrica_id'] ? '' : 'no se ha declarado movida';
            }],

        // Sin esto, cada corrida añadiría otra copia debajo y el docblock crecería solo.
        ['correrla dos veces no la duplica ni la vuelve a mover',
            function () use ($cols, $aMano): string {
                $una = reescribir(modeloDeControl(" * @property int \$id\n".$aMano), 'Cosa', 'cosas', $cols);
                $dos = reescribir($una['fuente'], 'Cosa', 'cosas', $cols);

                if (substr_count($dos['fuente'], 'rubrica_id') !== 1) {
                    return 'aparece '.substr_count($dos['fuente'], 'rubrica_id').' veces, y tiene que aparecer 1';
                }

                return $dos['movidas'] === [] ? '' : 'la segunda corrida vuelve a declararla movida';
            }],

        // El caso `Year.php`: lo que ya estaba debajo no es asunto de la herramienta.
        ['una @property que ya vive fuera del bloque no se toca ni se cuenta',
            function () use ($cols): string {
                $fuera = ' * @property string $regla_nivelacion';
                $r = reescribir(modeloDeControl(" * @property int \$id", $fuera), 'Cosa', 'cosas', $cols);

                if (substr_count($r['fuente'], 'regla_nivelacion') !== 1) {
                    return 'la duplicó o la borró';
                }

                return $r['movidas'] === [] ? '' : 'la contó como movida y no lo es';
            }],

        // La trampa de mover por LÍNEA en vez de por NOMBRE: un cambio de tipo en
        // el volcado dejaría la línea vieja fuera y la nueva dentro, o sea dos
        // `@property` del mismo nombre discrepando, que es peor que no anotar.
        ['un cambio de tipo de una columna real se regenera y NO deja dos líneas',
            function () use ($cols): string {
                $r = reescribir(modeloDeControl(" * @property int \$id\n * @property string \$nombre"), 'Cosa', 'cosas', $cols);

                if (substr_count($r['fuente'], '$nombre') !== 1) {
                    return 'quedan '.substr_count($r['fuente'], '$nombre').' líneas de $nombre';
                }
                if (! str_contains($r['fuente'], '@property ?string $nombre')) {
                    return 'no se regeneró al tipo del volcado';
                }

                return $r['movidas'] === [] ? '' : 'movió una columna que sí está en el volcado';
            }],

        // Lo que se pierde igual. Que se pierda no es el fallo; que se pierda
        // callando sí, porque nadie puede echar de menos lo que no vio irse.
        ['un comentario a mano sobre una línea generada se pierde, pero se AVISA',
            function () use ($cols): string {
                $r = reescribir(modeloDeControl(" * @property int \$id  ← ojo con esto"), 'Cosa', 'cosas', $cols);

                if (str_contains($r['fuente'], 'ojo con esto')) {
                    return 'lo conservó — entonces el aviso sobra y este control miente';
                }

                return array_keys($r['comentarios']) === ['id'] ? '' : 'se lo llevó SIN avisar';
            }],

        // Un modelo nuevo: se le mete el bloque y no hay nada que mover.
        ['un modelo sin bloque recibe uno nuevo y no mueve nada',
            function () use ($cols): string {
                $r = reescribir("<?php namespace App\Models;\n\nclass Cosa extends Model {}\n", 'Cosa', 'cosas', $cols);

                if (! str_contains($r['fuente'], MARCA_INICIO) || ! str_contains($r['fuente'], '@property ?string $nombre')) {
                    return 'no le puso el bloque';
                }

                return $r['movidas'] === [] && $r['comentarios'] === [] ? '' : 'declaró movimientos donde no había bloque';
            }],
    ];
}

/** Lo que hay entre las marcas, para poder preguntar «¿dentro o fuera?». */
function entreMarcas(string $fuente): string
{
    preg_match('/'.preg_quote(MARCA_INICIO, '/').'.*?'.preg_quote(MARCA_FIN, '/').'/s', $fuente, $m);

    return $m[0] ?? '';
}

function controlDeLasColumnas(): int
{
    $fallos = [];

    foreach (formasDeControl() as [$que, $comprobar]) {
        $mal = $comprobar();
        echo ($mal === '' ? '  ok    ' : '  FALLA ').$que."\n";

        if ($mal !== '') {
            $fallos[] = "    {$que}\n      -> {$mal}";
        }
    }

    echo 'Población del control: '.count(formasDeControl()).' formas comprobadas, '.count($fallos)." fallan.\n";

    if ($fallos !== []) {
        echo implode("\n", $fallos)."\n";
        echo "CONTROL FALLA: la herramienta volvió a poder borrar una `@property` escrita a mano.\n"
            ."Su forma de mentir es el SILENCIO — ninguna suite ejecuta tools/ y larastan sólo\n"
            ."deja de saber que la columna existe, así que esto no lo delata nada más.\n";

        return 1;
    }

    echo "OK — las seis formas se resuelven como está decidido, y ninguna borra.\n";

    return 0;
}

$argumentos = $argv ?? [];

if (in_array('--control', $argumentos, true)) {
    exit(controlDeLasColumnas());
}

// ─────────────────────────────────────────────────────────────────────────────
// A partir de aquí hace falta el volcado y el árbol.

$escribir = in_array('--escribir', $argumentos, true);

$esquema = file_get_contents(__DIR__.'/../database/schema/mysql-schema.sql');

if ($esquema === false) {
    fwrite(STDERR, "No se pudo leer el fichero\n");
    exit(1);
}

preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $esquema, $tablas, PREG_SET_ORDER);

$columnasPorTabla = [];
foreach ($tablas as [, $tabla, $cuerpo]) {
    // Se captura la línea entera, no solo el tipo: el resto es donde vive el
    // `NOT NULL`, y sin él la anotación miente en la dirección peligrosa —dice
    // `int` de una columna que devuelve null y el análisis deja de avisar de
    // los sitios que no lo contemplan.
    preg_match_all('/^  `(\w+)` (\w+)([^\n]*)/m', $cuerpo, $cols, PREG_SET_ORDER);
    foreach ($cols as [, $columna, $tipo, $resto]) {
        $columnasPorTabla[$tabla][$columna] = tipoPhp($tipo, str_contains($resto, 'NOT NULL'));
    }
}

$tocados = $saltados = $movidas = $comentarios = 0;

// `App\User` no vive en app/Models: se quedó en la raíz desde Laravel 5, y es
// justo el modelo del que más columnas se leen en todo el proyecto.
$modelos = array_merge(glob(__DIR__.'/../app/Models/*.php'), [__DIR__.'/../app/User.php']);

foreach ($modelos as $fichero) {
    $fuente = file_get_contents($fichero);

    if ($fuente === false) {
        fwrite(STDERR, "No se pudo leer el fichero\n");
        exit(1);
    }
    $clase = basename($fichero, '.php');

    $tabla = tablaDe($fuente, $clase, $columnasPorTabla);

    if ($tabla === null) {
        echo "  ? {$clase}: no se encontró su tabla en el esquema\n";
        $saltados++;

        continue;
    }

    $resultado = reescribir($fuente, $clase, $tabla, $columnasPorTabla[$tabla]);

    if ($resultado['fuente'] === $fuente) {
        continue;
    }

    $tocados++;
    echo '  ✎ '.str_pad($clase, 26).' → '.$tabla.' ('.count($columnasPorTabla[$tabla])." columnas)\n";

    foreach ($resultado['movidas'] as $nombre => $linea) {
        $movidas++;
        echo "      ↧ \${$nombre} no está en el volcado: se mueve DEBAJO de las marcas para que no se borre\n";
    }

    foreach ($resultado['comentarios'] as $nombre => $texto) {
        $comentarios++;
        echo "      ⚠ \${$nombre} es columna, así que su línea se regenera y este texto SE PIERDE: {$texto}\n";
    }

    if ($escribir) {
        file_put_contents($fichero, $resultado['fuente']);
    }
}

echo "\n{$tocados} modelos".($escribir ? ' escritos' : ' cambiarían')."; {$saltados} sin tabla; "
    ."{$movidas} @property movidas fuera del bloque; {$comentarios} comentarios que se pierden.\n";

if (! $escribir && $tocados) {
    echo "Nada se ha tocado. Repite con --escribir.\n";
}
