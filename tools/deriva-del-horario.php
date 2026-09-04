<?php

/**
 * Deriva del horario: **cuántas asignaciones dicen otra cosa que la versión oficial**.
 *
 * Contesta una pregunta que hoy no contesta nadie y que no se puede contestar
 * leyendo el código: *¿siguen cuadrando las siete columnas de día de `asignaturas`
 * con las lecciones de la versión que está publicada?*
 *
 * ## Por qué existe: hay DOS escritores de esas columnas
 *
 * Lo destapó `myvc-front-4f` el 4 sep 2026 ([23-horarios.md §9.bis.4](../docs/migracion/23-horarios.md)):
 *
 *   1. **`toggleDia`**, la pantalla de asignaturas del front, que conmuta la columna
 *      de una fila. Vive **hoy** en los dieciséis colegios y **se queda**: Joseth
 *      cerró ese mismo día que esos booleanos son *«un esfuerzo por mostrarle sólo
 *      las materias de hoy y de mañana al docente en el panel»* y que **tienen que
 *      seguir sirviendo a un colegio que nunca use el sistema de horarios**.
 *   2. **`putOficial`**, que al publicar una versión **reescribe las siete columnas
 *      de todo el alcance del año** desde las lecciones de esa versión (§7.1).
 *
 * **En la dirección «publicar» la colisión ya está resuelta**, y se resolvió antes de
 * saber que había dos escritores: lo que `putOficial` borra de lo puesto a mano es
 * exactamente lo que cuenta `acepto_perder`, así que hay una persona tecleando ese
 * número cada vez (§7.2).
 *
 * **En la contraria no hay nada, y es lo que mide esta herramienta.** Conmutar un día
 * después de publicar descuadra las dos pantallas **sin error y sin aviso**, y quien
 * lo hace no está haciendo nada raro: está usando la aplicación. El radio no es un
 * menú opcional — `horario_hoy` y `horario_manana` de `ChangesAsked/to-me` se derivan
 * de estas mismas columnas, o sea que lo que se descuadra es **la portada con la que
 * aterriza todo docente del colegio**.
 *
 * ## Lo que NO mira, dicho aquí y repetido en la salida
 *
 * - **La franja, la duración y el salón.** Las siete columnas no las tienen, así que
 *   una lección movida de la franja 2 a la 5 el mismo día **cuadra** y esta
 *   herramienta dice que todo está bien. Es correcto: lo que compara es *qué días*,
 *   que es lo único que las dos fuentes afirman a la vez.
 * - **Los años sin versión oficial.** Ahí no hay contra qué comparar y sale **2, NO
 *   MEDIDO** — nunca 0. Un año recién publicado da 0 descuadradas casi siempre, y un
 *   año sin publicar daría 0 también: son la misma cifra con significados opuestos.
 * - **Los otros colegios.** Una corrida mide **una** base. Los dieciséis son un bucle
 *   y quince silencios no son quince ceros.
 * - **Quién descuadró.** Esta herramienta dice que las dos fuentes discrepan, no cuál
 *   de las dos tiene razón: puede ser un `toggleDia` de ayer o una versión publicada
 *   con `acepto_perder` a sabiendas. Las dos se ven igual desde aquí.
 *
 * ## Uso
 *
 *     docker exec 8myvc-app-1 php tools/deriva-del-horario.php
 *     docker exec 8myvc-app-1 php tools/deriva-del-horario.php --year=8 --detalle
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/deriva-del-horario.php
 *     php tools/deriva-del-horario.php --control      # sin base y sin árbol
 *
 * Tres códigos de salida: `0` cuadran todas, `1` hay descuadradas, `2` **no medido**.
 */

// ─────────────────────────────────────────────────────────────────────────────
// La decisión que puede equivocarse EN SILENCIO, fuera del flujo para poder
// ejercerla sin base: el convenio del día.
//
// `horario_lecciones.dia` va de 0 a 6 con **0 = domingo** (§5.2.5), que es el
// convenio con el que se consumen las siete columnas sobre `Carbon::dayOfWeek`.
// Equivocarse aquí no da error: **da un horario corrido un día**, con las tres
// reglas de la §6 cumpliéndose igual. Es el fallo que este repositorio lleva dos
// documentos evitando, así que el mapa se declara una vez y se prueba.

/** El nombre de la columna de `asignaturas` que corresponde a un `dia`, o `null`. */
function columnaDelDia(int $dia): ?string
{
    $columnas = [0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miercoles',
        4 => 'jueves', 5 => 'viernes', 6 => 'sabado'];

    return $columnas[$dia] ?? null;
}

/** Las siete, en el orden del convenio. */
function lasSieteColumnas(): array
{
    return array_map(static fn (int $d): string => (string) columnaDelDia($d), range(0, 6));
}

/**
 * La autoprueba: ejercita el convenio y **sabe ponerse roja**.
 */
function controlDeLaDeriva(): int
{
    $fallos = [];

    if (columnaDelDia(0) !== 'domingo') {
        $fallos[] = 'el día 0 tiene que ser domingo (§5.2.5): con lunes, el horario entero se corre un día';
    }

    if (columnaDelDia(6) !== 'sabado') {
        $fallos[] = 'el día 6 tiene que ser sábado';
    }

    if (columnaDelDia(7) !== null || columnaDelDia(-1) !== null) {
        $fallos[] = 'un día fuera de 0..6 tiene que devolver null, no una columna cualquiera';
    }

    if (count(array_unique(lasSieteColumnas())) !== 7) {
        $fallos[] = 'las siete columnas tienen que ser siete nombres distintos';
    }

    echo "control de deriva-del-horario.php — 4 comprobaciones sobre el convenio del día\n";

    foreach ($fallos as $f) {
        echo "  FALLO: {$f}\n";
    }

    echo $fallos === []
        ? "  OK — 4 de 4. (Esto NO mide ninguna base: sólo el mapa día → columna.)\n"
        : '  '.count($fallos)." de 4 falladas.\n";

    return $fallos === [] ? 0 : 1;
}

if (in_array('--control', $argv ?? [], true)) {
    exit(controlDeLaDeriva());
}

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Una excepción no puede salir con 0: en el bucle de los dieciséis, el colegio cuya
// base no conteste es justo el que necesita el aviso. Sale 2 porque no se pudo mirar.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n!! NO MEDIDO — la base no contestó\n\n   ".$e->getMessage()."\n\n"
        ."   Esto NO es «este colegio cuadra»: es que no se ha mirado ni una fila.\n");
    exit(2);
});

$opciones = getopt('', ['year::', 'detalle', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3400), PHP_EOL;
    exit(0);
}

$base = DB::selectOne('SELECT DATABASE() AS d')->d;

$year = isset($opciones['year']) && $opciones['year'] !== false
    ? DB::selectOne('SELECT id, horario_version_id FROM years WHERE id = ?', [(int) $opciones['year']])
    : DB::selectOne('SELECT id, horario_version_id FROM years WHERE actual = 1 ORDER BY id DESC LIMIT 1');

echo "\nDeriva del horario — base `{$base}`\n";
echo str_repeat('─', 72), "\n";

if ($year === null) {
    fwrite(STDERR, "!! NO MEDIDO — no hay año que mirar (ni `--year` válido ni ninguno con `actual = 1`).\n");
    exit(2);
}

$yearId = (int) $year->id;

// **El caso que hace falta separar del cero.** Sin versión oficial no hay contra qué
// comparar, y «0 descuadradas» diría exactamente lo mismo que un año publicado y
// perfecto. Son la misma cifra con significados opuestos, así que aquí no se imprime
// ninguna.
if ($year->horario_version_id === null) {
    echo "año {$yearId}: **sin versión oficial** — `years.horario_version_id` está en NULL.\n\n";
    fwrite(STDERR, "!! NO MEDIDO — este año no ha publicado ningún horario, así que no hay con qué\n"
        ."   comparar las siete columnas. NO es «cuadra»: es que la pregunta no se puede hacer.\n");
    exit(2);
}

$versionId = (int) $year->horario_version_id;

// El alcance es **el año entero**, no las filas de la versión, y por la misma razón
// que en `putOficial` (§7): si la versión quitó las horas de una asignación, esa fila
// se queda con el día de la anterior y el docente sigue viendo una clase que ya no
// existe. Y `asignaturas` **no tiene `year_id`**: el año le llega por `grupos`, así
// que esto es un JOIN y no un WHERE — equivocarse ahí mide el año que no es y el
// resultado sale creíble.
$condiciones = [];

foreach (range(0, 6) as $dia) {
    $columna = columnaDelDia($dia);
    $condiciones[] = "a.{$columna} <> (EXISTS (SELECT 1 FROM horario_lecciones hl "
        ."WHERE hl.version_id = ? AND hl.asignatura_id = a.id AND hl.dia = {$dia}))";
}

$parametros = array_merge(array_fill(0, 7, $versionId), [$yearId]);

$alcance = (int) DB::selectOne(
    'SELECT COUNT(*) AS n FROM asignaturas a JOIN grupos g ON g.id = a.grupo_id
      WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL',
    [$yearId]
)->n;

$descuadradas = DB::select(
    'SELECT a.id, a.lunes, a.martes, a.miercoles, a.jueves, a.viernes, a.sabado, a.domingo,
            m.materia, g.nombre AS grupo
       FROM asignaturas a
       JOIN grupos g ON g.id = a.grupo_id
       LEFT JOIN materias m ON m.id = a.materia_id
      WHERE g.year_id = ? AND a.deleted_at IS NULL AND g.deleted_at IS NULL
        AND ('.implode(' OR ', $condiciones).')
      ORDER BY g.orden, a.orden, a.id',
    array_merge([$yearId], array_fill(0, 7, $versionId))
);

$lecciones = (int) DB::selectOne(
    'SELECT COUNT(*) AS n FROM horario_lecciones WHERE version_id = ?',
    [$versionId]
)->n;

// Las lecciones cuya asignación **no está en el alcance** — borrada, o su grupo movido
// a otro año entre que se subió y hoy. Sus días no los escribe nadie, así que el
// horario pierde esas clases en silencio. Es la misma cifra que `putOficial` cuenta y
// deja pasar a propósito (§7.1): se cuenta y se nombra, no se convierte en error.
$fuera = (int) DB::selectOne(
    'SELECT COUNT(*) AS n
       FROM horario_lecciones hl
       LEFT JOIN asignaturas a ON a.id = hl.asignatura_id AND a.deleted_at IS NULL
       LEFT JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
      WHERE hl.version_id = ? AND g.id IS NULL',
    [$yearId, $versionId]
)->n;

$cuantas = count($descuadradas);

echo "año {$yearId} · versión oficial {$versionId} · {$lecciones} lecciones\n\n";
echo "  asignaciones en el alcance del año  {$alcance}\n";
echo '  cuadran las siete columnas          '.($alcance - $cuantas)."\n";
echo "  DESCUADRADAS                        {$cuantas}\n";
echo "  lecciones fuera del alcance         {$fuera}   (sus días no los escribe nadie)\n\n";

if ($alcance === 0) {
    fwrite(STDERR, "!! NO MEDIDO — el año {$yearId} no tiene ni una asignación viva, así que el 0 de\n"
        ."   arriba no distingue «cuadran todas» de «no había nada que mirar».\n");
    exit(2);
}

if (isset($opciones['detalle']) && $descuadradas !== []) {
    echo "  qué dice cada una (columna → lo que dice la versión oficial):\n";

    foreach ($descuadradas as $fila) {
        $dice = [];

        foreach (range(0, 6) as $dia) {
            $columna = columnaDelDia($dia);
            $enLaVersion = (int) DB::selectOne(
                'SELECT EXISTS (SELECT 1 FROM horario_lecciones WHERE version_id = ? AND asignatura_id = ? AND dia = ?) AS e',
                [$versionId, $fila->id, $dia]
            )->e;

            if ((int) $fila->{$columna} !== $enLaVersion) {
                $dice[] = "{$columna}: ".(int) $fila->{$columna}.' → '.$enLaVersion;
            }
        }

        printf("    #%-6d %-22s %-14s %s\n", $fila->id, (string) $fila->materia, (string) $fila->grupo, implode(' · ', $dice));
    }

    echo "\n";
}

echo "  LO QUE ESTA CORRIDA NO MIRÓ, y no es lo mismo que no haberlo encontrado:\n";
echo "    · la franja, la duración y el salón — las siete columnas no los tienen, así que\n";
echo "      una lección movida de franja el mismo día CUADRA aquí;\n";
echo "    · los otros años de este colegio, y los otros quince colegios: una corrida es una base;\n";
echo "    · cuál de las dos fuentes tiene razón — un `toggleDia` de ayer y una versión\n";
echo "      publicada a sabiendas se ven exactamente igual desde aquí.\n\n";

if ($cuantas === 0) {
    echo "  Cuadran las {$alcance}.\n";
    echo "  Y una advertencia sobre este cero: recién publicada una versión, cuadrar es lo\n";
    echo "  normal — `putOficial` acaba de reescribir las siete. Este cero vale por lo que\n";
    echo "  dice DESPUÉS, no el mismo día.\n\n";
    exit(0);
}

echo "  {$cuantas} de {$alcance} descuadradas. Con `--detalle` sale cuál y en qué día.\n\n";
exit(1);
