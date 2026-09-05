<?php

/**
 * Pre-vuelo del horario, **nivel 1**: si los datos de un colegio sirven para
 * cuadrar un horario, antes de escribir ni una línea del generador.
 *
 * Es lo único del pre-vuelo de tres niveles que **es de este repo** y no del
 * escritorio ([23-horarios.md §9](../docs/migracion/23-horarios.md)). No toca el
 * router, no necesita una decisión de Joseth y no necesita rejilla de verdad:
 * es aritmética sobre lo que ya hay. Contesta la pregunta que puede tumbar el
 * proyecto entero —**si los datos de los quince colegios sirven**— en una tarde
 * en vez de en la demo.
 *
 * Lo que mira, por docente y por grupo, es Σ de intensidad horaria contra el
 * techo de la rejilla, más las asignaciones que **no tienen a quién poner en la
 * casilla**.
 *
 * Uso (dentro del contenedor):
 *
 *     docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php
 *     docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php --year=8 --detalle
 *     docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php --lecciones=6
 *     docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php --lecciones-nivel=Preescolar:5,Primaria:6
 *     docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php --csv
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/prevuelo-del-horario.php
 *     php tools/prevuelo-del-horario.php --control        # sin base y sin árbol
 *
 * Sin `--year` mira el año con `actual = 1`. **No escribe nada**: sólo `SELECT`.
 *
 * Tres códigos de salida, no dos, y el tercero es el que importa en el bucle de
 * los quince: `0` nivel 1 **limpio**, `1` nivel 1 **sucio** (hay hallazgos), `2`
 * **NO MEDIDO**. Un colegio no medido no es un colegio limpio, y con `--csv` a un
 * fichero es peor, porque no sale ninguna fila y el CSV de los quince parece
 * completo.
 *
 * **El 2 cubre todos los abortos, no sólo el de la base que no contesta**, y eso
 * es de un caso concreto: `--lecciones-nivel` se escribe una vez y se corre
 * quince, y los niveles educativos **se llaman distinto en cada colegio**. Con
 * `exit(1)` ahí, el colegio cuyo nivel no existe entraría en el recuento como
 * «sucio» —o sea, como uno mirado— cuando no se ha mirado ni una fila.
 *
 * ---
 *
 * ## Lo que ya se equivocó, y por eso está escrito aquí
 *
 * **La rejilla es un parámetro, no una constante escondida.** La v1 del plan
 * supuso **6 × 5 = 30 casillas** y con ese supuesto el docente de 31 horas de
 * `simonbolivar` era **imposible**: el proyecto no tenía solución. La rejilla
 * real del colegio es **7 × 5 = 35** —salió de un pantallazo de la configuración
 * de aSc— y a ese mismo docente le sobran cuatro. *El dato que decidía si el
 * problema tenía solución no era del algoritmo: era un desplegable.* Por eso
 * `--lecciones` y `--dias` se imprimen en la cabecera de cada corrida: quien lea
 * el informe tiene que ver contra qué techo se midió.
 *
 * **`asignaturas` NO tiene `year_id`.** El año le llega por `grupos.year_id`, o
 * sea que acotar por año es un **JOIN** y no un `WHERE`. Equivocarse ahí no da
 * error: mide el año que no es y el resultado sale creíble.
 *
 * **`creditos` es `int DEFAULT NULL`, y una IH nula no se evapora: desaparece
 * del `SUM`.** El total sale cuadrado habiendo mirado de menos, que es la forma
 * de mentir que no se nota. En `simonbolivar` son 0 de 134, pero de los otros
 * catorce colegios no se sabe nada — así que se cuentan y se nombran aparte, y
 * el bloque de intensidades **dice si el total está completo**.
 *
 * **La papelera se filtra y se dice.** Hay 240 asignaciones borradas en la tabla
 * entera, y `Asignatura::detallada()` **no filtra `a.deleted_at`**: sirve filas
 * de la papelera. Ese descuido no se hereda aquí, pero tampoco se esconde — el
 * bloque 0 dice cuántas descartó.
 *
 * **Y los JOIN de profesor y materia son `LEFT` a propósito.** Con `INNER`, una
 * asignación cuyo docente esté borrado **desaparecería de la población** y el
 * informe saldría más limpio de lo que es. Es la misma lección que costó el
 * `INNER JOIN profesores` de `detallada()`, donde una materia sin docente no
 * devolvía ninguna fila y el 404 decía lo que no era. Aquí lo que hace falta es
 * exactamente lo contrario de esconderlas: **una asignación sin a quién poner en
 * la casilla es el hallazgo, no una fila que sobra**.
 *
 * ## Ninguna línea dice OK sin decir su población
 *
 * Un «0 encontrados» no distingue *«revisé 134 y ninguna lo era»* de *«no revisé
 * nada»*, y de las dos lecturas la falsa es la que hace archivar el asunto. Cada
 * bloque dice cuántas miró, cuántas cuadran y cuántas no.
 *
 * ## El hallazgo que esto tiene que saber decir
 *
 * En `simonbolivar` las diez asignaciones sin docente **son las diez de
 * preescolar**, y no están repartidas: **Transición tiene 7 de 7** —el grupo
 * entero— y Jardín 3 de 7. Dicho como lo dice esta herramienta: *el horario de
 * Transición no se puede colocar en absoluto, porque ninguna de sus siete
 * asignaciones tiene a quién poner en la casilla.*
 *
 * Y la consecuencia, que la midió el front y es la que convierte esto en
 * bloqueante: **mientras el nivel 1 esté sucio, el nivel 2 —el emparejamiento,
 * la condición de Hall— no se ejecuta**, porque no tiene sentido buscar
 * imposibilidades finas mientras hay una gruesa sin resolver. O sea que esas
 * diez asignaciones no bloquean un grupo: **bloquean el diagnóstico de los
 * trece**.
 *
 * ## La jornada por nivel, que es un dato que falta
 *
 * Joseth cerró el 2 sep 2026 que la jornada es **distinta por nivel**:
 * preescolar, primaria y bachillerato con su propio número de lecciones. Los
 * timbres reales de cada nivel **no están** todavía, así que de serie esto mide
 * a todos contra la misma rejilla y `--lecciones-nivel` deja poner la de cada
 * uno el día que se sepan.
 *
 * La clave de ese parámetro es el `abrev` de `niveles_educativos` **tal y como
 * está en la base**, no las tres palabras de la decisión: en `simonbolivar` hay
 * **cuatro** niveles —Preescolar, Primaria, Secundaria y Media—, y traducir
 * «bachillerato» a dos de ellos es una decisión de cada colegio, no de esta
 * herramienta. Por eso el bloque de grupos imprime los niveles que encontró.
 *
 * El techo de un **docente** se toma como el mayor de los niveles en los que da
 * clase, y no como el suyo, porque un docente no tiene nivel: da clase donde le
 * pongan, y las casillas en las que cabe son las de la jornada más larga de las
 * que toca.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Las decisiones de este fichero que pueden equivocarse EN SILENCIO, sacadas del
// flujo para poder ejercerlas sin base y sin árbol. Lo hace `--control`, y lo
// invoca `tests/Unit/AutopruebasDeLasHerramientasTest`.
//
// No cubren el SQL —para eso habría que fabricar filas, y esto es sólo SELECT a
// propósito—: anclan lo que se decide en PHP, que es justo donde está el error
// que ya costó el proyecto entero una vez (la rejilla de 6 × 5).

/**
 * Reparte las intensidades horarias, y **dice si el total está completo**.
 *
 * `SUM(creditos)` en SQL se salta los nulos sin avisar: el total sale cuadrado
 * habiendo mirado de menos. Aquí `completo` es falso en cuanto haya una sola IH
 * nula, y esa bandera es lo que impide que el informe diga «Σ = 345» como si
 * fueran las 134.
 *
 * El **0** se separa del nulo porque no son lo mismo: un nulo es un dato que
 * falta, y un 0 es una asignación que **declara no ocupar ninguna casilla** —
 * cuadra en la aritmética y aun así no se puede colocar.
 *
 * @param  list<int|null>  $intensidades
 * @return array{poblacion: int, con_ih: int, sin_ih: int, cero: int, negativa: int, suma: int, completo: bool}
 */
function resumirIntensidades(array $intensidades): array
{
    $resumen = [
        'poblacion' => count($intensidades),
        'con_ih' => 0,
        'sin_ih' => 0,
        'cero' => 0,
        'negativa' => 0,
        'suma' => 0,
        'completo' => true,
    ];

    foreach ($intensidades as $ih) {
        if ($ih === null) {
            $resumen['sin_ih']++;
            $resumen['completo'] = false;

            continue;
        }

        $resumen['con_ih']++;
        $resumen['suma'] += $ih;

        if ($ih === 0) {
            $resumen['cero']++;
        } elseif ($ih < 0) {
            $resumen['negativa']++;
        }
    }

    return $resumen;
}

/**
 * Cada carga contra su techo, con el más cargado y los que no caben.
 *
 * **Es la función que la v1 tenía mal**, y no por su código: por el techo que le
 * llegaba. Con 6 × 5 = 30 casillas el docente de 31 horas era imposible; con
 * 7 × 5 = 35 le sobran cuatro. Por eso el techo es un parámetro por fila y no una
 * constante, y por eso el control fija las dos respuestas para la misma carga.
 *
 * `holgura` puede ser 0 y eso **cabe**: un docente con las 35 horas justas tiene
 * horario, sólo que único. El `>` del corte es la diferencia entre «apretado» e
 * «imposible».
 *
 * @param  list<array{quien: string, horas: int, techo: int}>  $cargas
 * @return array{poblacion: int, caben: int, no_caben: list<array{quien: string, horas: int, techo: int, exceso: int}>, mas_cargado: array{quien: string, horas: int, techo: int, holgura: int}|null}
 */
function cargaContraElTecho(array $cargas): array
{
    $noCaben = [];
    $masCargado = null;

    foreach ($cargas as $c) {
        if ($c['horas'] > $c['techo']) {
            $noCaben[] = [
                'quien' => $c['quien'],
                'horas' => $c['horas'],
                'techo' => $c['techo'],
                'exceso' => $c['horas'] - $c['techo'],
            ];
        }

        if ($masCargado === null || $c['horas'] > $masCargado['horas']) {
            $masCargado = [
                'quien' => $c['quien'],
                'horas' => $c['horas'],
                'techo' => $c['techo'],
                'holgura' => $c['techo'] - $c['horas'],
            ];
        }
    }

    return [
        'poblacion' => count($cargas),
        'caben' => count($cargas) - count($noCaben),
        'no_caben' => $noCaben,
        'mas_cargado' => $masCargado,
    ];
}

/**
 * Reparte los grupos por **cómo** les faltan docentes, que no es lo mismo que
 * cuántos les faltan.
 *
 * Éste es el hallazgo de `simonbolivar` convertido en función: diez asignaciones
 * sin docente repartidas por igual serían una molestia, y **concentradas en un
 * grupo entero son un horario que no se puede colocar en absoluto**. Contarlas
 * juntas esconde exactamente la diferencia que importa.
 *
 * El corte lleva `total > 0` a propósito: un grupo **sin ninguna asignación**
 * tiene 0 de 0 sin docente y eso no es «el grupo entero sin docente» — es otro
 * hallazgo, y se cuenta aparte, porque el arreglo es otro.
 *
 * @param  list<array{grupo: string, total: int, sin_docente: int, horas: int}>  $porGrupo
 * @return array{poblacion: int, enteros: list<string>, parciales: list<string>, limpios: int, vacios: list<string>, asignaciones: int, horas: int}
 */
function repartoSinDocente(array $porGrupo): array
{
    $reparto = [
        'poblacion' => count($porGrupo),
        'enteros' => [],
        'parciales' => [],
        'limpios' => 0,
        'vacios' => [],
        'asignaciones' => 0,
        'horas' => 0,
    ];

    foreach ($porGrupo as $g) {
        $reparto['asignaciones'] += $g['sin_docente'];
        $reparto['horas'] += $g['horas'];

        if ($g['total'] === 0) {
            $reparto['vacios'][] = $g['grupo'];
        } elseif ($g['sin_docente'] === $g['total']) {
            $reparto['enteros'][] = $g['grupo'];
        } elseif ($g['sin_docente'] > 0) {
            $reparto['parciales'][] = $g['grupo'];
        } else {
            $reparto['limpios']++;
        }
    }

    return $reparto;
}

/**
 * El veredicto, y la frase que lo hace bloqueante.
 *
 * Lo que convierte esto en una herramienta y no en un informe es la segunda
 * mitad: **mientras el nivel 1 esté sucio el nivel 2 no se ejecuta**, así que un
 * hallazgo en un grupo no bloquea ese grupo — bloquea el diagnóstico de todos.
 * Sin esa frase, un lector cuenta diez asignaciones sobre 134, le parecen pocas
 * y archiva el asunto.
 *
 * @param  list<string>  $hallazgos
 * @return array{limpio: bool, texto: string}
 */
function veredictoDelNivel1(array $hallazgos, int $grupos): array
{
    if ($hallazgos === []) {
        return [
            'limpio' => true,
            'texto' => "NIVEL 1 LIMPIO sobre los {$grupos} grupos mirados: la aritmética no encuentra "
                ."ninguna imposibilidad.\nEso NO dice que el horario se pueda cuadrar — lo dice el "
                .'nivel 2, que ya se puede ejecutar.',
        ];
    }

    return [
        'limpio' => false,
        'texto' => 'NIVEL 1 SUCIO: '.count($hallazgos)." hallazgo(s).\n  - "
            .implode("\n  - ", $hallazgos)
            ."\n\nY esto no bloquea sólo a quien sale nombrado: mientras el nivel 1 esté sucio, el\n"
            ."nivel 2 —el emparejamiento, la condición de Hall— NO se ejecuta, porque no tiene\n"
            ."sentido buscar imposibilidades finas mientras hay una gruesa sin resolver. O sea que\n"
            ."estos hallazgos bloquean el diagnóstico de los {$grupos} grupos, no el de los suyos.",
    ];
}

/**
 * El aviso de que se midió un año **que no es el actual**, para el RESUMEN final.
 *
 * ## Por qué no basta con la cabecera, que ya lo dice
 *
 * La cabecera imprime `año 2026 (year_id 9, NO es el actual)` desde siempre. **Y se
 * pierde**, porque esta herramienta se lee de dos maneras que se comen justo esa
 * línea: `| tail` en el bucle de los diecisiete, y el ojo que baja al veredicto.
 * Un aviso que sólo vive arriba **es un aviso que no existe en el único momento en
 * que hace falta**.
 *
 * ## Lo que esto NO arregla, y va dicho porque es lo que engaña
 *
 * **El código de salida sigue siendo el mismo `1`.** Medido el 4 sep 2026 sobre
 * `simonbolivar`: `--year=9` —el 2026, sin un solo docente asignado— imprime un
 * informe completo y creíble (13 grupos, 134 asignaciones, ΣIH 345) con **los trece
 * grupos imposibles**, y sale con `1` igual que un colegio de verdad sucio. En un
 * bucle de diecisiete ese colegio entra en el recuento como **mirado y sucio**.
 *
 * **Y no se cambia a `2`, a propósito.** `2` es NO MEDIDO, y aquí sí se ha medido:
 * un año pasado o futuro es un año **legítimo de mirar** —hay colegios que preparan
 * el siguiente—. Mover el código movería a quien lo consuma para arreglar una
 * lectura, que es lo que se arregla escribiendo. *Lo que estaba mal no era el
 * veredicto: era dónde se decía con qué año se sacó.*
 *
 * Sólo avisa si el año **se pidió a mano**: sin `--year` la herramienta coge el
 * `actual` ella sola y no hay nada que advertir.
 */
function avisoDelAnioMirado(bool $pedidoAMano, bool $esElActual, int $yearId, int $anio): string
{
    if (! $pedidoAMano || $esElActual) {
        return '';
    }

    return "\n⚠️  OJO CON EL AÑO: esto se midió sobre {$anio} (year_id {$yearId}), que **NO es el\n"
        ."    año actual** de esta base — lo pediste con `--year={$yearId}`.\n\n"
        ."    El veredicto de arriba es cierto PARA ESE AÑO. Un año que todavía no se ha\n"
        ."    configurado sale «sucio» porque está vacío, no porque tenga un problema, y el\n"
        ."    informe no se distingue del de un colegio con un problema de verdad.\n\n"
        .'    Y el código de salida NO lo distingue: sale `1` igual. Sin `--year` esta '
        ."herramienta\n    coge el año `actual` ella sola.\n";
}

/**
 * El control positivo, **sin base y sin árbol**: sólo las formas decididas.
 *
 * Lo ejecuta `tests/Unit/AutopruebasDeLasHerramientasTest`. Fija la conducta
 * conocida; **no descubre cegueras nuevas** — si mañana la consulta deja de
 * traer una familia de filas, esto sigue verde. Para eso no hay control: hay
 * leer las filas.
 */
function controlDelPreVuelo(): int
{
    $fallos = [];

    $comprobadas = 0;

    $comprobar = function (string $que, mixed $salio, mixed $esperado) use (&$fallos, &$comprobadas): void {
        $comprobadas++;
        $ok = $salio === $esperado;
        echo '  '.($ok ? 'ok  ' : 'FALLA')."  {$que}\n";

        if (! $ok) {
            $fallos[] = '    '.$que.': esperaba '.json_encode($esperado).' y salió '.json_encode($salio);
        }
    };

    // ── Intensidades. La forma de mentir es el nulo que desaparece del SUM.
    $comprobar('tres IH puestas suman y el total está completo',
        array_intersect_key(resumirIntensidades([5, 3, 2]), ['suma' => 0, 'sin_ih' => 0, 'completo' => 0]),
        ['sin_ih' => 0, 'suma' => 10, 'completo' => true]);

    // EL CASO. `SUM(creditos)` diría 10 sobre tres filas y parecería cuadrado.
    $comprobar('una IH NULA no se evapora: el total sigue siendo 10 pero NO está completo',
        array_intersect_key(resumirIntensidades([5, 5, null]), ['suma' => 0, 'sin_ih' => 0, 'completo' => 0]),
        ['sin_ih' => 1, 'suma' => 10, 'completo' => false]);

    // Un 0 cuadra en la aritmética y aun así no se puede colocar: se cuenta aparte.
    $comprobar('una IH de 0 NO es una IH que falte',
        array_intersect_key(resumirIntensidades([5, 0]), ['sin_ih' => 0, 'cero' => 0, 'completo' => 0]),
        ['sin_ih' => 0, 'cero' => 1, 'completo' => true]);

    $comprobar('una IH negativa se nombra y no se traga',
        resumirIntensidades([-1])['negativa'], 1);

    // Población 0 con completo=true NO es un aprobado: es lo que obliga a que la
    // línea del informe imprima siempre la población al lado.
    $comprobar('sin filas, la población es 0 y es lo único que se puede afirmar',
        array_intersect_key(resumirIntensidades([]), ['poblacion' => 0, 'suma' => 0]),
        ['poblacion' => 0, 'suma' => 0]);

    // ── El techo. EL ERROR DE LA V1, en las dos direcciones.
    $unDocenteDe31 = [['quien' => 'JOEL HERNÁNDEZ', 'horas' => 31, 'techo' => 30]];
    $comprobar('con la rejilla de 6 x 5 = 30, el docente de 31 h es IMPOSIBLE (el supuesto de la v1)',
        count(cargaContraElTecho($unDocenteDe31)['no_caben']), 1);

    $unDocenteDe31[0]['techo'] = 35;
    $comprobar('con la rejilla real de 7 x 5 = 35, el MISMO docente cabe con 4 de holgura',
        [count(cargaContraElTecho($unDocenteDe31)['no_caben']), cargaContraElTecho($unDocenteDe31)['mas_cargado']['holgura']],
        [0, 4]);

    // El corte es `>` y no `>=`: 35 sobre 35 tiene horario, sólo que único.
    $comprobar('las horas justas del techo CABEN, con holgura 0',
        array_intersect_key(cargaContraElTecho([['quien' => 'x', 'horas' => 35, 'techo' => 35]]), ['caben' => 0, 'no_caben' => 0]),
        ['caben' => 1, 'no_caben' => []]);

    $comprobar('el más cargado sale aunque quepa, y con su exceso si no cabe',
        cargaContraElTecho([
            ['quien' => 'a', 'horas' => 10, 'techo' => 35],
            ['quien' => 'b', 'horas' => 40, 'techo' => 35],
        ])['no_caben'][0]['exceso'], 5);

    // ── El reparto. Diez repartidas son una molestia; concentradas, un imposible.
    $reparto = repartoSinDocente([
        ['grupo' => 'Transición', 'total' => 7, 'sin_docente' => 7, 'horas' => 20],
        ['grupo' => 'Jardín', 'total' => 7, 'sin_docente' => 3, 'horas' => 5],
        ['grupo' => 'Primero', 'total' => 10, 'sin_docente' => 0, 'horas' => 0],
    ]);
    $comprobar('7 de 7 es el GRUPO ENTERO y 3 de 7 no lo es',
        [$reparto['enteros'], $reparto['parciales'], $reparto['limpios']],
        [['Transición'], ['Jardín'], 1]);
    $comprobar('y las horas y las asignaciones se suman de todos los grupos',
        [$reparto['asignaciones'], $reparto['horas']], [10, 25]);

    // 0 de 0 NO es «el grupo entero sin docente»: es un grupo sin asignaciones,
    // y el arreglo es otro. Con un corte sin `total > 0` saldría como entero.
    $comprobar('un grupo SIN asignaciones no es un grupo entero sin docente',
        array_intersect_key(repartoSinDocente([['grupo' => 'Sexto B', 'total' => 0, 'sin_docente' => 0, 'horas' => 0]]),
            ['enteros' => 0, 'vacios' => 0]),
        ['enteros' => [], 'vacios' => ['Sexto B']]);

    // ── El veredicto, que es donde vive la frase que lo hace bloqueante.
    $comprobar('sin hallazgos, el nivel 1 está limpio', veredictoDelNivel1([], 13)['limpio'], true);
    $comprobar('con un hallazgo, sucio Y dice que el nivel 2 no se ejecuta',
        [veredictoDelNivel1(['algo'], 13)['limpio'], str_contains(veredictoDelNivel1(['algo'], 13)['texto'], 'nivel 2')],
        [false, true]);
    $comprobar('y dice el diagnóstico de CUÁNTOS grupos bloquea, no sólo el suyo',
        str_contains(veredictoDelNivel1(['algo'], 13)['texto'], 'los 13 grupos'), true);

    // ── El aviso del año. Su forma de mentir es CALLARSE, así que las tres formas
    // de callarse legítimamente se fijan una a una: callarse de más aquí no se ve
    // —no falta nada en la pantalla, sólo un aviso que nadie echa en falta—.
    $comprobar('sin `--year`, no hay nada que advertir: la herramienta cogió el actual',
        avisoDelAnioMirado(false, true, 8, 2025), '');
    $comprobar('con `--year` sobre el ACTUAL tampoco: es lo mismo que no pasarlo',
        avisoDelAnioMirado(true, true, 8, 2025), '');
    // El caso raro que igual no se avisa: sin `--year` la herramienta sólo puede
    // haber cogido el `actual`, así que un «no es el actual» aquí sería imposible.
    $comprobar('sin `--year` y sin ser el actual —imposible por construcción— tampoco avisa',
        avisoDelAnioMirado(false, false, 9, 2026), '');

    // EL CASO. Y se comprueba lo que DICE, no que diga algo: un aviso que no
    // nombra el año ni desmiente el código de salida deja el informe igual de
    // creíble, que es de lo que venía.
    $aviso = avisoDelAnioMirado(true, false, 9, 2026);
    $comprobar('con `--year` sobre un año que NO es el actual, avisa',
        $aviso !== '', true);
    $comprobar('y el aviso nombra el año, el id y la opción con la que se pidió',
        [str_contains($aviso, '2026'), str_contains($aviso, 'year_id 9'), str_contains($aviso, '--year=9')],
        [true, true, true]);
    $comprobar('y DESMIENTE el código de salida, que es lo que el bucle de los diecisiete lee',
        str_contains($aviso, 'código de salida NO lo distingue'), true);

    echo "Población del control: {$comprobadas} formas comprobadas, ".count($fallos)." fallan.\n";

    if ($fallos !== []) {
        echo implode("\n", $fallos)."\n";
        echo "CONTROL FALLA: esta herramienta decide si los datos de un colegio sirven para\n"
            ."cuadrar un horario. Su número NO vale hasta arreglar esto — y sus dos formas de\n"
            ."mentir son decir que un total está completo cuando hay IH nulas, y medir contra\n"
            ."una rejilla que no es la del colegio.\n";

        return 1;
    }

    echo "OK — las {$comprobadas} formas se deciden como está escrito.\n";

    return 0;
}

if (in_array('--control', $argv ?? [], true)) {
    exit(controlDelPreVuelo());
}

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Una excepción aquí NO puede salir con 0. El bootstrap de Laravel pinta la
// excepción muy bien —con su traza y sus colores— y devuelve cero; da igual
// mientras alguien la mire, y deja de dar igual en las quince corridas, que es
// como se usa esto. El colegio cuya base no conteste es justo el que necesita el
// aviso. Sale 2 porque no es un hallazgo ni un fallo de la herramienta: es que
// **no se pudo mirar**.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n!! NO MEDIDO — la base no contestó\n\n   ".$e->getMessage()."\n\n"
        ."   Esto NO es «este colegio tiene los datos limpios»: es que no se ha mirado ni una\n"
        ."   fila. Si sale dentro del bucle de los quince, ese colegio queda SIN MEDIR y su\n"
        ."   número no está en el recuento — comprueba a qué base apunta `DB_DATABASE`.\n");
    exit(2);
});

$opciones = getopt('', ['year::', 'lecciones::', 'dias::', 'lecciones-nivel::', 'detalle', 'csv', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 5200), PHP_EOL;
    exit(0);
}

/**
 * El valor de una opción, y **aborta si llegó más de una vez**.
 *
 * `getopt()` devuelve un **array** cuando la misma opción aparece repetida, y un
 * `(string)`/`(int)` sobre un array se traga el segundo valor con un aviso que
 * nadie ve en un bucle de quince. `--lecciones=7 --lecciones=6` mediría contra un
 * techo que el usuario no pidió, y el informe imprimiría en su cabecera el techo
 * equivocado con toda la seguridad del mundo — que es exactamente el fallo de la
 * v1 otra vez, sólo que llegando por la línea de órdenes.
 *
 * @param  array<string, mixed>  $opciones
 */
function valorUnico(array $opciones, string $nombre): ?string
{
    $valor = $opciones[$nombre] ?? null;

    if ($valor === null || $valor === false) {
        return null;
    }

    if (is_array($valor)) {
        fwrite(STDERR, "ABORTO: `--{$nombre}` llegó ".count($valor)." veces, y este informe se mediría\n"
            ."contra una sola de ellas sin decir cuál. Pásala una vez.\n");
        exit(2);
    }

    return (string) $valor;
}

$detalle = isset($opciones['detalle']);
$csv = isset($opciones['csv']);
$lecciones = (int) (valorUnico($opciones, 'lecciones') ?? 7);
$dias = (int) (valorUnico($opciones, 'dias') ?? 5);

if ($lecciones < 1 || $dias < 1) {
    fwrite(STDERR, "ABORTO: la rejilla llegó como {$lecciones} × {$dias}, y un techo de 0 casillas haría\n"
        ."imposible a todo el mundo. `--lecciones` y `--dias` van en positivo.\n");
    exit(2);
}

/*
 * `--lecciones-nivel=Preescolar:5,Primaria:6`.
 *
 * La clave es el `abrev` de `niveles_educativos` **como esté en la base**, no las
 * tres palabras de la decisión de Joseth: en `simonbolivar` hay cuatro niveles y
 * traducir «bachillerato» a dos de ellos es de cada colegio. Un nivel que no se
 * nombre se mide con `--lecciones`.
 *
 * Un nombre que no exista en la base **aborta** en vez de ignorarse: escribirlo
 * mal —o con el acento comido por la terminal— daría un informe medido contra el
 * techo de serie y con pinta de estar medido contra el que se pidió.
 */
$leccionesPorNivel = [];
$leccionesNivelCrudo = valorUnico($opciones, 'lecciones-nivel');

if ($leccionesNivelCrudo !== null && $leccionesNivelCrudo !== '') {
    foreach (explode(',', $leccionesNivelCrudo) as $par) {
        $trozos = explode(':', $par);

        if (count($trozos) !== 2 || ! is_numeric($trozos[1])) {
            fwrite(STDERR, "ABORTO: `--lecciones-nivel` va como `Nivel:7,Otro:6`, y llegó «{$par}».\n");
            exit(2);
        }

        $leccionesPorNivel[trim($trozos[0])] = (int) $trozos[1];
    }
}

$base = (string) config('database.connections.mysql.database');

/*
 * El año, por `actual = 1` si no lo dan. Y si no hay ninguno —o hay varios—, se
 * aborta: elegir uno «el primero que salga» daría un informe entero del año que
 * no es, y el informe no tiene forma de parecer sospechoso.
 */
$anioPedidoAMano = ($yearPedido = valorUnico($opciones, 'year')) !== null;

if ($anioPedidoAMano) {
    $yearId = (int) $yearPedido;
} else {
    $actuales = DB::select('select id from years where actual = 1 and deleted_at is null');

    if (count($actuales) !== 1) {
        fwrite(STDERR, 'ABORTO: hay '.count($actuales)." años con `actual = 1` en `{$base}`, y hace falta\n"
            ."exactamente uno para saber cuál mirar. Pásalo a mano con `--year=<id>`.\n");
        exit(2);
    }

    $yearId = (int) $actuales[0]->id;
}

$year = DB::select('select id, year, actual from years where id = ?', [$yearId]);

if ($year === []) {
    fwrite(STDERR, "ABORTO: no existe el año {$yearId} en `{$base}`.\n");
    exit(2);
}

$anio = (int) $year[0]->year;
$esElActual = (int) $year[0]->actual === 1;

// ─────────────────────────────────────────────────────────────────────────────
// LAS TRES LECTURAS. Todo el reparto se hace en PHP, sobre estas filas, para que
// lo que decide el informe sean las funciones de arriba —las que tienen control—
// y no un `GROUP BY` que nadie puede ejercer sin base.

/*
 * Los grupos vivos del año, **todos**, también los que no tienen ni una
 * asignación. Salen de aquí y no de la consulta de asignaciones porque un grupo
 * sin asignaciones no tiene ninguna fila allí: contándolos desde las
 * asignaciones, el grupo que peor está sería justo el que no aparece.
 */
$grupos = DB::select('
    select g.id, g.nombre, gr.orden as grado_orden, ne.abrev as nivel
    from grupos g
    left join grados gr on gr.id = g.grado_id
    left join niveles_educativos ne on ne.id = gr.nivel_educativo_id
    where g.year_id = ? and g.deleted_at is null
    order by gr.orden, g.nombre
', [$yearId]);

/*
 * Las asignaciones vivas del año.
 *
 * El año entra por `grupos.year_id` —**JOIN, no WHERE**: `asignaturas` no tiene
 * `year_id`—, y los join de profesor y materia son `LEFT` a propósito: con
 * `INNER`, una asignación cuyo docente o cuya materia estén borrados
 * desaparecería de la población y el informe saldría más limpio de lo que es.
 */
$asignaciones = DB::select('
    select a.id, a.creditos, a.profesor_id, a.grupo_id, a.materia_id,
           p.id as profesor_existe, p.deleted_at as profesor_borrado,
           p.nombres, p.apellidos,
           m.id as materia_existe, m.deleted_at as materia_borrada, m.materia
    from asignaturas a
    join grupos g on g.id = a.grupo_id and g.deleted_at is null
    left join profesores p on p.id = a.profesor_id
    left join materias m on m.id = a.materia_id
    where g.year_id = ? and a.deleted_at is null
    order by a.grupo_id, a.id
', [$yearId]);

/*
 * Lo que se descartó, que va en el informe porque un filtro que no se dice es un
 * filtro que nadie puede comprobar. La tercera cifra es un hallazgo de verdad:
 * una asignación **viva** colgando de un grupo **borrado** no sale en la consulta
 * de arriba y aun así existe.
 */
$descartadas = DB::select('
    select
      (select count(*) from asignaturas a join grupos g on g.id = a.grupo_id
        where g.year_id = ? and a.deleted_at is not null)                       as borradas_del_anio,
      (select count(*) from asignaturas where deleted_at is not null)           as borradas_en_la_tabla,
      (select count(*) from asignaturas a join grupos g on g.id = a.grupo_id
        where g.year_id = ? and a.deleted_at is null
          and g.deleted_at is not null)                                         as vivas_bajo_grupo_borrado
', [$yearId, $yearId]);

// ─────────────────────────────────────────────────────────────────────────────
// EL REPARTO, en PHP.

$techoBase = $lecciones * $dias;

/** @var array<int, array{nombre: string, nivel: string, techo: int, total: int, sin_docente: int, horas: int, ih: list<int|null>}> $porGrupo */
$porGrupo = [];

foreach ($grupos as $g) {
    $nivel = $g->nivel === null ? '(sin nivel)' : (string) $g->nivel;
    $leccionesDelNivel = $leccionesPorNivel[$nivel] ?? $lecciones;

    $porGrupo[(int) $g->id] = [
        'nombre' => (string) $g->nombre,
        'nivel' => $nivel,
        'techo' => $leccionesDelNivel * $dias,
        'total' => 0,
        'sin_docente' => 0,
        'horas' => 0,
        'ih' => [],
    ];
}

$nivelesNombrados = array_keys($leccionesPorNivel);
$nivelesEnLaBase = array_values(array_unique(array_map(static fn (array $g): string => $g['nivel'], $porGrupo)));
$nivelesQueNoExisten = array_diff($nivelesNombrados, $nivelesEnLaBase);

if ($nivelesQueNoExisten !== []) {
    fwrite(STDERR, 'ABORTO: `--lecciones-nivel` nombra niveles que no existen en `'.$base.'`: '
        .implode(', ', $nivelesQueNoExisten)."\n"
        .'Los que hay son: '.implode(', ', $nivelesEnLaBase)."\n"
        ."Sin esto, el informe se mediría contra el techo de serie con pinta de estar medido\n"
        ."contra el que pediste.\n");
    exit(2);
}

/** @var array<int, array{nombre: string, horas: int, asignaciones: int, niveles: list<string>}> $porDocente */
$porDocente = [];

$intensidades = [];
$sinDocente = [];
$docenteBorrado = [];
$docenteInexistente = [];
$materiaBorrada = [];
$materiaInexistente = [];

foreach ($asignaciones as $a) {
    $grupoId = (int) $a->grupo_id;

    // Un grupo que no esté en `$porGrupo` es imposible por construcción —las dos
    // consultas filtran igual—, pero si pasara, la asignación se perdería en
    // silencio y el informe cuadraría de menos. Se nombra.
    if (! isset($porGrupo[$grupoId])) {
        fwrite(STDERR, "ABORTO: la asignación {$a->id} cuelga del grupo {$grupoId}, que no está entre los\n"
            ."grupos vivos del año. Las dos consultas han dejado de mirar la misma población:\n"
            ."ningún número de este informe vale.\n");
        exit(2);
    }

    $ih = $a->creditos === null ? null : (int) $a->creditos;
    $horas = $ih ?? 0;

    $intensidades[] = $ih;
    $porGrupo[$grupoId]['total']++;
    $porGrupo[$grupoId]['ih'][] = $ih;

    $comoSeLlama = trim(($a->nombres ?? '').' '.($a->apellidos ?? ''));
    $quienEs = $porGrupo[$grupoId]['nombre'].' · '.($a->materia ?? "materia {$a->materia_id}");

    if ($a->materia_existe === null) {
        $materiaInexistente[] = $quienEs;
    } elseif ($a->materia_borrada !== null) {
        $materiaBorrada[] = $quienEs;
    }

    /*
     * Las tres formas de «no hay a quién poner en la casilla» se cuentan juntas
     * para el horario y **se nombran por separado**, porque el arreglo de cada
     * una es distinto: asignar un docente, restaurarlo de la papelera o mirar
     * por qué el `profesor_id` apunta a una fila que no existe.
     */
    if ($a->profesor_id === null) {
        $sinDocente[] = $quienEs;
    } elseif ($a->profesor_existe === null) {
        $docenteInexistente[] = $quienEs." (profesor_id {$a->profesor_id})";
    } elseif ($a->profesor_borrado !== null) {
        $docenteBorrado[] = $quienEs.' ('.$comoSeLlama.')';
    }

    $colocable = $a->profesor_id !== null && $a->profesor_existe !== null && $a->profesor_borrado === null;

    if (! $colocable) {
        $porGrupo[$grupoId]['sin_docente']++;
        $porGrupo[$grupoId]['horas'] += $horas;

        continue;
    }

    $profesorId = (int) $a->profesor_id;

    if (! isset($porDocente[$profesorId])) {
        $porDocente[$profesorId] = [
            'nombre' => $comoSeLlama === '' ? "profesor {$profesorId}" : $comoSeLlama,
            'horas' => 0,
            'asignaciones' => 0,
            'niveles' => [],
        ];
    }

    $porDocente[$profesorId]['horas'] += $horas;
    $porDocente[$profesorId]['asignaciones']++;

    if (! in_array($porGrupo[$grupoId]['nivel'], $porDocente[$profesorId]['niveles'], true)) {
        $porDocente[$profesorId]['niveles'][] = $porGrupo[$grupoId]['nivel'];
    }
}

$resumenIh = resumirIntensidades($intensidades);

/*
 * El techo de un docente es el mayor de los niveles en los que da clase, no el
 * suyo: un docente no tiene nivel —da clase donde le pongan— y las casillas en
 * las que cabe son las de la jornada más larga de las que toca. Con la rejilla
 * de serie los techos son todos iguales y esto no se nota; con `--lecciones-nivel`
 * sí, y entonces la alternativa —darle el techo del nivel más corto— marcaría
 * como imposibles a docentes que tienen horario.
 */
$cargasDocentes = [];

foreach ($porDocente as $d) {
    $techosDeSusNiveles = array_map(
        static fn (string $n): int => ($leccionesPorNivel[$n] ?? $lecciones) * $dias,
        $d['niveles']
    );

    $cargasDocentes[] = [
        'quien' => $d['nombre'],
        'horas' => $d['horas'],
        // Un docente sin niveles es imposible aquí —está en esta lista porque tiene
        // asignaciones, y toda asignación cuelga de un grupo—, pero el `max()` de un
        // array vacío es un `ValueError`, y ese sería un colegio NO MEDIDO por una
        // rejilla, no por la base.
        'techo' => $techosDeSusNiveles === [] ? $techoBase : max($techosDeSusNiveles),
    ];
}

usort($cargasDocentes, static fn (array $a, array $b): int => $b['horas'] <=> $a['horas']);

$cargasGrupos = [];
$repartoEntrada = [];

foreach ($porGrupo as $g) {
    $suma = 0;

    foreach ($g['ih'] as $ih) {
        $suma += $ih ?? 0;
    }

    $cargasGrupos[] = ['quien' => $g['nombre'].' ('.$g['nivel'].')', 'horas' => $suma, 'techo' => $g['techo']];
    $repartoEntrada[] = [
        'grupo' => $g['nombre'],
        'total' => $g['total'],
        'sin_docente' => $g['sin_docente'],
        'horas' => $g['horas'],
    ];
}

$docentes = cargaContraElTecho($cargasDocentes);
$gruposContraTecho = cargaContraElTecho($cargasGrupos);
$reparto = repartoSinDocente($repartoEntrada);

// ─────────────────────────────────────────────────────────────────────────────
// LOS HALLAZGOS. Lo que hace que el nivel 1 esté sucio, con su frase.

$hallazgos = [];

foreach ($reparto['enteros'] as $g) {
    $hallazgos[] = "el horario de {$g} no se puede colocar EN ABSOLUTO: ninguna de sus asignaciones "
        .'tiene a quién poner en la casilla';
}

if ($reparto['parciales'] !== []) {
    $hallazgos[] = count($reparto['parciales']).' grupo(s) con asignaciones sin docente: '
        .implode(', ', $reparto['parciales']);
}

if ($reparto['vacios'] !== []) {
    $hallazgos[] = count($reparto['vacios']).' grupo(s) sin NINGUNA asignación: '
        .implode(', ', $reparto['vacios']);
}

if (! $resumenIh['completo']) {
    $hallazgos[] = $resumenIh['sin_ih'].' asignación(es) con la intensidad horaria SIN PONER: el total '
        .'de horas está medido de menos y no se puede afirmar que quepa';
}

if ($resumenIh['cero'] > 0) {
    $hallazgos[] = $resumenIh['cero'].' asignación(es) con intensidad horaria 0: cuadran en la '
        .'aritmética y aun así no ocupan ninguna casilla';
}

if ($resumenIh['negativa'] > 0) {
    $hallazgos[] = $resumenIh['negativa'].' asignación(es) con intensidad horaria NEGATIVA';
}

foreach ($docentes['no_caben'] as $d) {
    $hallazgos[] = "{$d['quien']} tiene {$d['horas']} h y sólo caben {$d['techo']}: le sobran "
        ."{$d['exceso']}, así que NO tiene horario posible";
}

foreach ($gruposContraTecho['no_caben'] as $g) {
    $hallazgos[] = "{$g['quien']} suma {$g['horas']} h y sólo caben {$g['techo']}: le sobran {$g['exceso']}";
}

if ($materiaBorrada !== []) {
    $hallazgos[] = count($materiaBorrada).' asignación(es) viva(s) con la MATERIA en la papelera: '
        .'`GET asignaturas` no las devuelve ni como fila ni como aviso, así que el importador '
        .'no puede contar lo que no le mandaron';
}

if ($materiaInexistente !== []) {
    $hallazgos[] = count($materiaInexistente).' asignación(es) apuntando a una materia que NO EXISTE';
}

$vivasBajoGrupoBorrado = (int) $descartadas[0]->vivas_bajo_grupo_borrado;

if ($vivasBajoGrupoBorrado > 0) {
    $hallazgos[] = "{$vivasBajoGrupoBorrado} asignación(es) viva(s) colgando de un grupo BORRADO: no "
        .'están en ninguna cifra de este informe y existen';
}

$veredicto = veredictoDelNivel1($hallazgos, count($grupos));

// ─────────────────────────────────────────────────────────────────────────────
// LA SALIDA.

if ($csv) {
    /*
     * Una línea por colegio, para juntar los quince. Es opt-in y no cambia ni un
     * carácter de la salida de siempre.
     */
    $campos = [
        'base' => $base,
        'year_id' => $yearId,
        'year' => $anio,
        // Las dos van juntas y no sobra ninguna: `es_el_actual` dice **qué se miró**
        // y `year_pedido_a_mano` dice **por qué**. Un CSV de diecisiete colegios con
        // una fila medida sobre un año vacío se lee como un colegio con problemas, y
        // sin estas dos columnas no hay forma de verlo después — la cabecera que lo
        // avisa no viaja en el CSV.
        'es_el_actual' => $esElActual ? 1 : 0,
        'year_pedido_a_mano' => $anioPedidoAMano ? 1 : 0,
        'lecciones' => $lecciones,
        'dias' => $dias,
        'grupos' => count($grupos),
        'asignaciones' => count($asignaciones),
        'docentes' => count($porDocente),
        'suma_ih' => $resumenIh['suma'],
        'sin_ih' => $resumenIh['sin_ih'],
        'ih_cero' => $resumenIh['cero'],
        'sin_docente' => $reparto['asignaciones'],
        'horas_sin_docente' => $reparto['horas'],
        'grupos_enteros_sin_docente' => count($reparto['enteros']),
        'grupos_vacios' => count($reparto['vacios']),
        'docentes_que_no_caben' => count($docentes['no_caben']),
        'grupos_que_no_caben' => count($gruposContraTecho['no_caben']),
        'docente_mas_cargado' => $docentes['mas_cargado']['horas'] ?? 0,
        'materia_en_papelera' => count($materiaBorrada),
        'integridad_rota' => count($docenteBorrado) + count($docenteInexistente)
            + count($materiaInexistente) + $vivasBajoGrupoBorrado,
        'hallazgos' => count($hallazgos),
        'veredicto' => $veredicto['limpio'] ? 'limpio' : 'sucio',
    ];

    echo implode(',', array_keys($campos)).PHP_EOL;
    echo implode(',', array_map(static fn (mixed $v): string => (string) $v, $campos)).PHP_EOL;

    exit($veredicto['limpio'] ? 0 : 1);
}

/*
 * `mb_str_pad` y no `printf("%-42s")`: los nombres de los grupos y media docena de
 * etiquetas llevan tilde, y `printf` cuenta BYTES. Con `%-42s` cada acento corre
 * una columna y la tabla sale desalineada justo en las filas que se leen.
 */
$linea = static function (string $que, int|string $cuantos, string $de = ''): void {
    echo '   '.mb_str_pad($que, 42).' '.mb_str_pad((string) $cuantos, 6, ' ', STR_PAD_LEFT).' '.$de."\n";
};

echo "\n";
echo "PRE-VUELO DEL HORARIO — NIVEL 1 (aritmética sobre los datos que ya hay)\n";
echo "base `{$base}` · año {$anio} (year_id {$yearId}".($esElActual ? ', el actual' : ', NO es el actual').")\n";
echo "rejilla {$lecciones} lecciones × {$dias} días = {$techoBase} casillas"
    .($leccionesPorNivel === [] ? ' (igual para todos los niveles)' : ' de serie, y por nivel: ')
    .($leccionesPorNivel === [] ? '' : implode(', ', array_map(
        static fn (string $n, int $l): string => $n.' '.$l.'×'.$dias.'='.($l * $dias),
        array_keys($leccionesPorNivel), array_values($leccionesPorNivel)
    )))."\n";
echo str_repeat('─', 78)."\n";

echo "\n0. LA POBLACIÓN MIRADA\n";
$linea('Grupos vivos del año', count($grupos));
$linea('Asignaciones vivas', count($asignaciones));
$linea('Niveles educativos encontrados', count($nivelesEnLaBase), implode(', ', $nivelesEnLaBase));
$linea('Descartadas: en la papelera, de este año', (int) $descartadas[0]->borradas_del_anio,
    '(de '.$descartadas[0]->borradas_en_la_tabla.' en la tabla entera)');
$linea('Vivas colgando de un grupo BORRADO', $vivasBajoGrupoBorrado,
    $vivasBajoGrupoBorrado > 0 ? '<- existen y no están en ninguna cifra de abajo' : '');

echo "\n1. LA INTENSIDAD HORARIA — `creditos` es NULL-able, y un nulo desaparece del SUM\n";
$linea('Asignaciones con IH puesta', $resumenIh['con_ih'], 'de '.$resumenIh['poblacion']);
$linea('Asignaciones SIN IH (null)', $resumenIh['sin_ih'],
    $resumenIh['sin_ih'] > 0 ? '<- estas horas NO están en el total' : '');
$linea('Asignaciones con IH 0', $resumenIh['cero']);
$linea('Asignaciones con IH negativa', $resumenIh['negativa']);
$linea('Σ de intensidades', $resumenIh['suma'],
    $resumenIh['completo']
        ? 'h/semana — completo: las '.$resumenIh['poblacion'].' tienen IH'
        : 'h/semana — INCOMPLETO: falta la IH de '.$resumenIh['sin_ih']);

echo "\n2. LOS DOCENTES CONTRA EL TECHO\n";
$linea('Docentes con clase', $docentes['poblacion']);
$linea('Caben en su rejilla', $docentes['caben'], 'de '.$docentes['poblacion']);
$linea('NO caben (imposibles)', count($docentes['no_caben']));

if ($docentes['mas_cargado'] !== null) {
    $m = $docentes['mas_cargado'];
    echo "   El más cargado: {$m['quien']}, {$m['horas']} h de {$m['techo']} — "
        .($m['holgura'] >= 0 ? "{$m['holgura']} de holgura" : 'le sobran '.abs($m['holgura']))."\n";
    echo '   Σ IH por docente: '.implode(' · ', array_map(
        static fn (array $c): string => (string) $c['horas'], $cargasDocentes))."\n";
}

echo "\n3. LOS GRUPOS CONTRA EL TECHO\n";
$linea('Grupos mirados', $gruposContraTecho['poblacion']);
$linea('Caben en su rejilla', $gruposContraTecho['caben'], 'de '.$gruposContraTecho['poblacion']);
$linea('NO caben', count($gruposContraTecho['no_caben']));

if ($detalle) {
    foreach ($cargasGrupos as $g) {
        echo '     '.mb_str_pad($g['quien'], 34).' '
            .mb_str_pad((string) $g['horas'], 3, ' ', STR_PAD_LEFT).' h de '
            .mb_str_pad((string) $g['techo'], 3, ' ', STR_PAD_LEFT)."\n";
    }
}

echo "\n4. A QUIÉN PONER EN LA CASILLA\n";
$linea('Grupos mirados', $reparto['poblacion']);
$linea('Grupos sin ninguna asignación sin docente', $reparto['limpios'], 'de '.$reparto['poblacion']);
$linea('Grupos con el GRUPO ENTERO sin docente', count($reparto['enteros']),
    $reparto['enteros'] === [] ? '' : implode(', ', $reparto['enteros']));
$linea('Grupos con parte sin docente', count($reparto['parciales']),
    $reparto['parciales'] === [] ? '' : implode(', ', $reparto['parciales']));
$linea('Grupos sin NINGUNA asignación', count($reparto['vacios']),
    $reparto['vacios'] === [] ? '' : implode(', ', $reparto['vacios']));
$linea('Asignaciones sin a quién poner', $reparto['asignaciones'],
    'de '.count($asignaciones).', que son '.$reparto['horas'].' h');

echo '     de ellas, `profesor_id` nulo ....... '.count($sinDocente)."\n";
echo '     de ellas, el docente está BORRADO .. '.count($docenteBorrado)."\n";
echo '     de ellas, el docente NO EXISTE ..... '.count($docenteInexistente)."\n";

if ($detalle) {
    foreach (['sin docente' => $sinDocente, 'docente borrado' => $docenteBorrado,
        'docente inexistente' => $docenteInexistente] as $que => $lista) {
        foreach ($lista as $fila) {
            echo "     [{$que}] {$fila}\n";
        }
    }
}

echo "\n5. LA MATERIA DETRÁS DE CADA ASIGNACIÓN\n";
$linea('Con la materia viva', count($asignaciones) - count($materiaBorrada) - count($materiaInexistente),
    'de '.count($asignaciones));
$linea('Con la materia EN LA PAPELERA', count($materiaBorrada),
    count($materiaBorrada) > 0 ? '<- `GET asignaturas` no las devuelve' : '');
$linea('Apuntando a una materia que NO EXISTE', count($materiaInexistente));

if ($detalle) {
    foreach (array_merge($materiaBorrada, $materiaInexistente) as $fila) {
        echo "     {$fila}\n";
    }
}

echo "\n".str_repeat('─', 78)."\n";
echo $veredicto['texto']."\n\n";

// Va DESPUÉS del veredicto y no antes: lo último que se imprime es lo único que
// sobrevive a un `| tail`, que es como se lee el bucle de los diecisiete.
echo avisoDelAnioMirado($anioPedidoAMano, $esElActual, $yearId, $anio);

exit($veredicto['limpio'] ? 0 : 1);
