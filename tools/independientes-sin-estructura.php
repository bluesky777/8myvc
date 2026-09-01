<?php

/**
 * Qué pares (alumno, asignatura) van por boletín aparte y **no tienen ni una
 * unidad propia**.
 *
 *     php tools/independientes-sin-estructura.php               # todos los periodos con marcados
 *     php tools/independientes-sin-estructura.php --periodo=91  # uno
 *     php tools/independientes-sin-estructura.php --year=12     # todos los del año
 *     php tools/independientes-sin-estructura.php --control     # su control positivo, sin base
 *
 * Es la §9.1 de [docs/migracion/19-boletin-independiente.md](../docs/migracion/19-boletin-independiente.md)
 * —*el alumno que se cae por el hueco*— y es **el único riesgo del módulo que no
 * avisa de ninguna forma**. Un alumno marcado sin estructura propia tiene su
 * definitiva en **0** y su boletín **en blanco**, y nadie recibe un error: la
 * consulta no falla, devuelve cero filas, y cero filas se leen como cero.
 *
 * ## Y hoy es PEOR que cuando se escribió el plan
 *
 * Lo midió el lote F el 1 sep 2026, con la fase 1 ya fundida: un marcado sin
 * unidades propias **ni siquiera aparece** en el informe de notas perdidas. La
 * consulta pide `u.alumno_id <=> ALCANCE` y no empareja con ninguna fila, así que
 * el alumno **desaparece de la lista** en vez de salir perdiéndolo todo.
 *
 * **El arreglo del alcance cambió el síntoma de sitio, y lo cambió a peor**: antes
 * la pantalla le acusaba de perderlo todo —falso, pero visible—; ahora **se lo
 * calla**. Un alumno que sale acusado de algo raro se mira; uno que no sale, no.
 * De las dos formas de fallar de la §9.2, la de menos es la que no deja rastro, y
 * esta herramienta es **lo único que puede verlo**.
 *
 * ## Por qué el `=` de aquí NO es el fallo caro de la §3
 *
 * La §1.6 del reparto está partida en dos, y este fichero cae del lado de la
 * derecha:
 *
 *     «¿qué unidades le TOCAN a este alumno?»  ->  `<=>`, el null-safe resuelve las dos ramas
 *     «¿cuáles son SUYAS?»                     ->  `=`, es una lectura que AFIRMA PROPIEDAD
 *
 * Aquí la pregunta es la segunda y no admite la primera: con `<=>` un alumno sin
 * nada propio emparejaría con las unidades **del grupo** y saldría con estructura.
 * O sea que el `<=>` no daría un número peor: **daría cero huecos siempre**, que
 * es justo la respuesta que hace archivar el asunto.
 *
 * ## Su población, y qué significa que sea cero
 *
 * Un «0 encontrados» no distingue *«revisé 466 pares y ninguno lo era»* de *«no
 * revisé nada»*, y de las dos lecturas la falsa es la que tranquiliza (CLAUDE.md).
 * Hoy la población real es **cero alumnos marcados** —`bol_ind_periodos` nace
 * vacía—, así que esta herramienta **no puede** decir «todo bien»: dice que el
 * módulo no está en uso todavía, que es una frase distinta.
 *
 * ## La base contra la que corre, dicha siempre
 *
 * Arranca Laravel, así que lee el `.env` y **pega contra la base de desarrollo,
 * no contra la de tests**. Eso es lo que se quiere de una herramienta de
 * coordinación, pero se imprime en la primera línea porque una consulta suelta
 * contra la base equivocada casi le cuesta una falsa alarma al lote F el 1 sep
 * 2026.
 */

// ─────────────────────────────────────────────────────────────────────────────
// La decisión, en funciones puras y separadas de la base a propósito.
//
// Es lo que permite que el `--control` de abajo no dependa **ni del árbol ni de
// la base**, que es la lección de `unidades-sin-alcance.py`: el detector que
// repartió el trabajo de una noche entera era el único sin control, y pudo
// equivocarse cinco veces porque contar de más no se delata solo.

/**
 * La clave con la que se pregunta si una unidad es de alguien, en un solo sitio.
 *
 * Existe para que el SQL y el control no puedan escribirla distinto: si esta
 * función cambia, cambian los dos a la vez o el control cae.
 */
function claveDelPar(int $alumnoId, int $asignaturaId, int $periodoId): string
{
    return $alumnoId.'|'.$asignaturaId.'|'.$periodoId;
}

/**
 * Los pares (alumno, asignatura) que van aparte y no tienen nada propio.
 *
 * @param  list<array{alumno_id: int, periodo_id: int, grupo_id: int}>  $marcados
 * @param  array<int, list<int>>  $asignaturasPorGrupo  grupo_id => sus asignaturas vivas
 * @param  list<string>  $conUnidadPropia  claves de `claveDelPar` con al menos una unidad VIVA suya
 * @return array{pares: int, huecos: list<array{alumno_id: int, asignatura_id: int, periodo_id: int}>}
 */
function huecosDeEstructura(array $marcados, array $asignaturasPorGrupo, array $conUnidadPropia): array
{
    $tiene = array_flip($conUnidadPropia);
    $pares = 0;
    $huecos = [];

    foreach ($marcados as $m) {
        // Un marcado cuyo grupo no tiene ni una asignatura no aporta pares, y no
        // es un hueco: no hay nada que montarle todavía. Contarlo como hueco
        // sería inventar trabajo; no contar sus cero pares sería mentir en la
        // población. Por eso se recorre igual y suma cero.
        foreach ($asignaturasPorGrupo[$m['grupo_id']] ?? [] as $asignaturaId) {
            $pares++;
            if (! isset($tiene[claveDelPar($m['alumno_id'], $asignaturaId, $m['periodo_id'])])) {
                $huecos[] = [
                    'alumno_id' => $m['alumno_id'],
                    'asignatura_id' => $asignaturaId,
                    'periodo_id' => $m['periodo_id'],
                ];
            }
        }
    }

    return ['pares' => $pares, 'huecos' => $huecos];
}

// ─────────────────────────────────────────────────────────────────────────────
// El control positivo, ejecutable y **antes del bootstrap**: no toca la base, no
// mira el árbol y no llama a `git`. Sus entradas son las cinco formas de abajo.
//
// Lo corre `tests/Unit/AutopruebasDeLasHerramientasTest`. Tres salidas y no dos,
// como manda ese runner: 0 pasa, 1 el detector cambió de opinión, 2 no concluye.

/** @return list<array{0: string, 1: array<string, mixed>, 2: int}> */
function casosDeControl(): array
{
    $marcado = ['alumno_id' => 7, 'periodo_id' => 91, 'grupo_id' => 3];
    $asignaturas = [3 => [11, 12]];

    return [
        ['el marcado sin NADA propio es un hueco por cada asignatura',
            ['marcados' => [$marcado], 'asignaturas' => $asignaturas, 'propias' => []], 2],
        ['con lo suyo montado en las dos, no hay hueco',
            ['marcados' => [$marcado], 'asignaturas' => $asignaturas,
                'propias' => ['7|11|91', '7|12|91']], 0],
        ['montado en una y en la otra no: el hueco es POR PAR, no por alumno',
            ['marcados' => [$marcado], 'asignaturas' => $asignaturas, 'propias' => ['7|11|91']], 1],
        // El caso que un `<=>` en el SQL borraría entero: las unidades del grupo
        // NO cuentan como suyas. Si algún día esto da 0, la consulta se pasó al
        // null-safe y la herramienta contesta «ningún hueco» para siempre.
        ['las unidades DEL GRUPO no tapan el hueco',
            ['marcados' => [$marcado], 'asignaturas' => $asignaturas,
                'propias' => ['0|11|91', '0|12|91']], 2],
        // Lo suyo en OTRO periodo tampoco: la marca es por periodo (decisión 7),
        // así que un alumno que fue aparte en el 2 y sigue aparte en el 3 con la
        // estructura sin copiar es exactamente la §9.4 fallando.
        ['lo suyo en OTRO periodo no cuenta: la marca es por periodo',
            ['marcados' => [$marcado], 'asignaturas' => $asignaturas,
                'propias' => ['7|11|90', '7|12|90']], 2],
    ];
}

function control(): int
{
    $fallos = [];

    foreach (casosDeControl() as [$que, $entrada, $esperado]) {
        $salio = count(huecosDeEstructura(
            $entrada['marcados'], $entrada['asignaturas'], $entrada['propias']
        )['huecos']);

        $marca = $salio === $esperado ? 'ok  ' : 'FALLA';
        echo "  {$marca} [huecos={$esperado}] {$que}\n";
        if ($salio !== $esperado) {
            $fallos[] = "    esperaba {$esperado} y salieron {$salio}: {$que}";
        }
    }

    echo 'Población del control: '.count(casosDeControl()).' formas comprobadas, '
        .count($fallos)." fallan.\n";

    if ($fallos !== []) {
        echo implode("\n", $fallos)."\n";
        echo "CONTROL FALLA: la herramienta cambió de opinión sobre qué es un hueco.\n"
            ."Su lista NO vale hasta arreglar esto — y su forma de mentir es decir CERO.\n";

        return 1;
    }

    echo "OK — las cinco formas se clasifican como está decidido.\n";

    return 0;
}

$argumentos = $argv ?? [];

if (in_array('--control', $argumentos, true)) {
    exit(control());
}

// ─────────────────────────────────────────────────────────────────────────────
// A partir de aquí hace falta la base.

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$soloPeriodo = null;
$soloYear = null;
foreach ($argumentos as $a) {
    if (str_starts_with($a, '--periodo=')) {
        $soloPeriodo = (int) substr($a, 10);
    }
    if (str_starts_with($a, '--year=')) {
        $soloYear = (int) substr($a, 7);
    }
}

// Una herramienta que revienta y sale con 0 es de la familia de
// `respuestas-que-mienten.py`: el bootstrap de Laravel pinta la excepción muy
// bien y devuelve **cero**, así que quien la llame desde un script la da por
// buena. Medido aquí mismo el 1 sep 2026 con la primera versión, que se equivocó
// de nombre de tabla y salió `exit=0` con la excepción en pantalla.
//
// Sale **2** y no 1 porque no es un hallazgo ni un fallo de la herramienta: es
// que **no se pudo mirar** — las mismas tres salidas del runner de autopruebas.
try {
    // La base va SIEMPRE en la primera línea, y dicha como lo que es —la que
    // resuelve la configuración— y no como «la de desarrollo»: con un
    // `DB_DATABASE=` delante corre contra otra, y una línea que afirmara cuál es
    // mentiría exactamente en el caso en que importa.
    echo 'Base: '.DB::connection()->getDatabaseName()
        ." (la que resuelve la configuración; de serie el `.env`, que NO es la de tests)\n\n";
    exit(medir($soloPeriodo, $soloYear));
} catch (Throwable $e) {
    fwrite(STDERR, "NO CONCLUYENTE: la base no contestó — ".$e->getMessage()."\n\n"
        ."NO uses esto como «ningún alumno se cae por el hueco»: no se ha revisado\n"
        ."ni un par. Comprueba contra qué base corre (`.env`) y que las migraciones\n"
        ."del boletín independiente estén puestas ahí.\n");
    exit(2);
}

/**
 * El barrido contra la base. Devuelve el código de salida: 0 siempre que haya
 * podido mirar — los huecos son un hallazgo, no un error de la herramienta.
 */
function medir(?int $soloPeriodo, ?int $soloYear): int
{

// Los marcados: `aplica = 1` es «va aparte en ese periodo». La fila ausente dice
// «va con el grupo» (decisión 7), así que aquí no hace falta ningún COALESCE:
// lo que no está en la tabla no es un marcado.
//
// El grupo sale de la matrícula VIVA del alumno en el año del periodo, con los
// tres estados que `Grupo::alumnos` considera dentro del grupo (MATR, ASIS,
// PREM). Un retirado no tiene boletín que montar.
$marcados = DB::select(
    'SELECT bip.alumno_id, bip.periodo_id, m.grupo_id, p.numero AS periodo_numero,
            al.nombres, al.apellidos, g.nombre AS grupo
       FROM bol_ind_periodos bip
       JOIN periodos p ON p.id = bip.periodo_id AND p.deleted_at IS NULL
       JOIN grupos g ON g.year_id = p.year_id AND g.deleted_at IS NULL
       JOIN matriculas m ON m.alumno_id = bip.alumno_id AND m.grupo_id = g.id
            AND m.estado IN ("MATR", "ASIS", "PREM") AND m.deleted_at IS NULL
       JOIN alumnos al ON al.id = bip.alumno_id AND al.deleted_at IS NULL
      WHERE bip.aplica = 1'
    .($soloPeriodo !== null ? ' AND bip.periodo_id = '.$soloPeriodo : '')
    .($soloYear !== null ? ' AND p.year_id = '.$soloYear : '')
    .' ORDER BY p.numero, al.apellidos, al.nombres'
);

$periodos = count(array_unique(array_map(static fn ($m) => $m->periodo_id, $marcados)));

if ($marcados === []) {
    // Y aquí es donde una herramienta se estropea: imprimir «OK, ningún hueco».
    echo "Población: 0 alumnos marcados (`bol_ind_periodos` con `aplica = 1`)"
        .($soloPeriodo !== null ? " en el periodo {$soloPeriodo}" : '')
        .($soloYear !== null ? " del año {$soloYear}" : '')."; 0 pares revisados.\n\n";
        echo "NO es «ningún alumno se cae por el hueco»: es que **no hay a quién revisar**.\n"
            ."El módulo del boletín independiente todavía no está en uso — la tabla nace\n"
            ."vacía y así sigue. La primera marca que se ponga hace que esto conteste algo.\n";

        return 0;
    }

$gruposDeInteres = array_values(array_unique(array_map(static fn ($m) => (int) $m->grupo_id, $marcados)));

$asignaturas = DB::select(
    'SELECT a.id, a.grupo_id, mat.materia
       FROM asignaturas a
       JOIN materias mat ON mat.id = a.materia_id
      WHERE a.deleted_at IS NULL AND a.grupo_id IN ('.implode(',', $gruposDeInteres).')'
);

$asignaturasPorGrupo = [];
$nombreAsignatura = [];
foreach ($asignaturas as $a) {
    $asignaturasPorGrupo[(int) $a->grupo_id][] = (int) $a->id;
    $nombreAsignatura[(int) $a->id] = $a->materia;
}

// `alumno_id = ` y NUNCA `<=>`: ver la cabecera. La pregunta es «¿cuáles son
// SUYAS?», y con el null-safe las del grupo taparían todos los huecos.
$propias = DB::select(
    'SELECT DISTINCT u.alumno_id, u.asignatura_id, u.periodo_id
       FROM unidades u
      WHERE u.deleted_at IS NULL AND u.alumno_id IS NOT NULL
        AND u.alumno_id IN ('.implode(',', array_unique(array_map(static fn ($m) => (int) $m->alumno_id, $marcados))).')'
);

$conUnidadPropia = array_map(
    static fn ($u) => claveDelPar((int) $u->alumno_id, (int) $u->asignatura_id, (int) $u->periodo_id),
    $propias
);

$entrada = array_map(static fn ($m) => [
    'alumno_id' => (int) $m->alumno_id,
    'periodo_id' => (int) $m->periodo_id,
    'grupo_id' => (int) $m->grupo_id,
], $marcados);

$resultado = huecosDeEstructura($entrada, $asignaturasPorGrupo, $conUnidadPropia);

$quien = [];
foreach ($marcados as $m) {
    $quien[$m->alumno_id.'|'.$m->periodo_id] = trim($m->apellidos.' '.$m->nombres)
        .' ('.$m->grupo.', periodo '.$m->periodo_numero.')';
}

$totalAsignaturas = count($nombreAsignatura);
echo 'Población: '.count($marcados).' marcados en '.$periodos.' periodo(s); '
    .$totalAsignaturas.' asignaturas en '.count($gruposDeInteres).' grupo(s); '
    .$resultado['pares']." pares revisados.\n\n";

if ($resultado['huecos'] === []) {
        echo '0 pares sin estructura propia: los '.$resultado['pares']." revisados tienen\n"
            ."al menos una unidad viva suya. (Esto sí es «ninguno se cae por el hueco».)\n";

        return 0;
    }

echo count($resultado['huecos'])." pares SIN estructura propia — su definitiva sale 0,\n"
    ."su boletín en blanco, y desde la fase 1 **ni siquiera salen en notas perdidas**:\n\n";

foreach ($resultado['huecos'] as $h) {
    printf(
        "  %-46s  %s\n",
        $quien[$h['alumno_id'].'|'.$h['periodo_id']] ?? ('alumno '.$h['alumno_id']),
        $nombreAsignatura[$h['asignatura_id']] ?? ('asignatura '.$h['asignatura_id'])
    );
}

    echo "\nQué hacer con cada uno, y son dos cosas distintas: montarle su estructura\n"
        ."(`PUT boletin-independiente/planilla`, §6.1) o **quitarle la marca de ese\n"
        ."periodo** si va con el grupo. Lo que no es una salida es dejarlo: nadie va a\n"
        ."recibir un error, y el cero se imprime igual.\n";

    return 0;
}
