<?php

/*
 * CÓMO USA UN COLEGIO DE VERDAD LOS REQUISITOS DE MATRÍCULA.
 *
 * Contesta una pregunta que desde el repositorio **no se puede contestar**: la
 * fase 1 del proceso de admisión —`myvc_front/PANTALLAS-MATRICULA.md`— **ensancha**
 * `requisitos_matricula` y `requisitos_alumno` en vez de empezar de cero, así que
 * lo que hay que saber antes de escribirla es **cómo se usan hoy donde se usan**:
 * cuántos pasos configura un colegio, cómo los llama, en qué orden, quién los
 * cierra y qué proporción se cierra de verdad.
 *
 * USO — EN EL SERVIDOR, o contra una copia
 *   php tools/requisitos-de-matricula.php                    # la base del .env de esta carpeta
 *   php tools/requisitos-de-matricula.php micolev1_lal_db    # una base concreta
 *   php tools/requisitos-de-matricula.php BASE [BASE…]       # varias, una tabla por base
 *   php tools/requisitos-de-matricula.php --csv BASE         # para pegar en otro sitio
 *
 * ES DE SÓLO LECTURA. Cinco `SELECT` y ninguna escritura.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE, Y POR QUÉ NO VALE MIRARLO EN DESARROLLO
 *
 * Medido el 20 sep 2026 en la copia de desarrollo (UN colegio): **una sola fila en
 * `requisitos_matricula` en todos los años, CERO en el año actual**, doce marcas de
 * alumno y las doce en «falta». Y la única fila se llama
 * `Fotocopia del documento (verificacion 234754)`, creada el 22 ago 2026 — un dato
 * de prueba, no un colegio trabajando.
 *
 * Leído sin denominador, eso dice «funcionalidad muerta» y hace archivar la fase 1.
 * **Es la lectura falsa.** Joseth lo contestó el mismo día —*«sí lo han usado en
 * otros colegios»*— y el 20 sep nombró cuál: **`lalvirtual`**.
 *
 * Así que la cifra de desarrollo no estaba mal: **contestaba bien a otra pregunta**,
 * la de un colegio que no usa esto. Es la regla de la casa —*ninguna herramienta
 * imprime OK sin decir su población*— y por eso esto imprime **el nombre de la base
 * en cada bloque** y nunca un total suelto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE SE PREGUNTA, Y POR QUÉ CADA UNA
 *
 *   1. CUÁNTOS PASOS por año y cómo se llaman.  La fase 1 propone un juego por
 *      defecto de once. Si un colegio real usa cuatro, once es un formulario que
 *      nadie rellena; si usa veinte, once se queda corto.
 *
 *   2. SI EL ORDEN SE USA.  `requisitos_matricula.orden` existe. Si están todos en
 *      cero, «el orden de las estaciones» es un concepto nuevo y hay que pedirlo;
 *      si están numerados, ya hay un recorrido que respetar.
 *
 *   3. QUIÉN LOS CIERRA HOY.  `editable_por_profe_id` es lo único parecido a un
 *      dueño que existe. Si está siempre en NULL, la columna `rol_id` de la fase 1
 *      no ensancha nada: **inventa** un concepto, y eso se decide, no se deduce.
 *
 *   4. QUÉ PROPORCIÓN SE CIERRA DE VERDAD.  `requisitos_alumno.estado` con todo en
 *      «falta» significa que se configuró y no se usó — que es distinto de que se
 *      use y se cierre. **Es la diferencia entre ensanchar algo vivo y resucitar
 *      algo muerto.**
 *
 *   5. CUÁNDO FUE LA ÚLTIMA VEZ.  Un colegio que lo usó en 2019 y no volvió no es
 *      un colegio que lo use.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE ESTO **NO** CONTESTA, dicho para que nadie lo suponga
 *
 * - **Cuántas estaciones tiene un día de matrículas.** Eso no está en ninguna
 *   tabla: hoy los números van impresos en cartulinas. Lo sabe el colegio.
 * - **Si el orden es de trabajo o de cárcel** —si un paso bloquea al siguiente—.
 *   La columna `orden` ordena; no dice si alguien la hace cumplir.
 * - **Si «falta» es que no se entregó o que nadie lo marcó.** Son indistinguibles
 *   desde la base, y la diferencia decide si la fase 1 arregla un problema de
 *   datos o uno de proceso.
 */

$args = array_slice($argv, 1);
$csv = in_array('--csv', $args, true);
$bases = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));

/* Sin base, la del `.env` de esta carpeta: es el caso de correrlo dentro de un colegio. */
if (count($bases) === 0) {
    $env = __DIR__.'/../.env';

    if (! is_readable($env)) {
        fwrite(STDERR, "No hay .env aquí y no diste una base.\n"
            ."  php tools/requisitos-de-matricula.php micolev1_lal_db\n");
        exit(2);
    }

    // `file()` devuelve `false` si el fichero se vuelve ilegible entre el
    // `is_readable` y aquí. No es paranoia de tipos: sin esto el `foreach` sobre
    // `false` es un error fatal en PHP 8, y el guion moriría sin decir por qué.
    foreach (file($env, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        if (preg_match('/^\s*DB_DATABASE\s*=\s*"?([^"\s]+)"?/', $linea, $m)) {
            $bases[] = $m[1];
        }
    }
}

$pdo = conectar();

foreach ($bases as $base) {
    $csv ? imprimirCsv($pdo, $base) : imprimirBloque($pdo, $base);
}

/* ────────────────────────────────────────────────────────────────────────── */

function conectar(): PDO
{
    $env = __DIR__.'/../.env';
    $cfg = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => ''];

    if (is_readable($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
            if (preg_match('/^\s*(DB_HOST|DB_PORT|DB_USERNAME|DB_PASSWORD)\s*=\s*"?([^"]*)"?\s*$/', $linea, $m)) {
                $cfg[$m[1]] = $m[2];
            }
        }
    }

    foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
        if (getenv($k) !== false) {
            $cfg[$k] = (string) getenv($k);
        }
    }

    try {
        return new PDO("mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};charset=utf8mb4",
            $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        fwrite(STDERR, "No se pudo conectar: {$e->getMessage()}\n"
            ."Las credenciales salen del .env de esta carpeta, o de DB_HOST/DB_USERNAME/DB_PASSWORD.\n");
        exit(2);
    }
}

/**
 * El nombre de la base **no puede ir como parámetro** —MySQL no lo admite ahí— así
 * que se acota a lo que puede ser un identificador. Es lo único que impide que un
 * argumento del shell acabe dentro de la consulta.
 */
function comprobarNombre(string $base): void
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $base) !== 1) {
        fwrite(STDERR, "«{$base}» no tiene forma de nombre de base.\n");
        exit(2);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function filas(PDO $pdo, string $base, string $sql): array
{
    comprobarNombre($base);

    // `query()` devuelve `false` si el driver está en modo silencioso. Aquí está en
    // `ERRMODE_EXCEPTION` —lo pone `conectar()`— así que no puede pasar; se
    // comprueba igual porque **esa configuración vive en otra función** y el día que
    // alguien la cambie, el fallo sería un «call to a member function on false» en
    // vez del mensaje que dice qué base no se pudo leer.
    $stmt = $pdo->query(str_replace('{B}', "`{$base}`", $sql));

    if ($stmt === false) {
        throw new PDOException('La consulta no devolvió resultado sobre `'.$base.'`.');
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function imprimirBloque(PDO $pdo, string $base): void
{
    comprobarNombre($base);   // antes de imprimir nada: un argumento raro no estrena cabecera

    echo "\n══════════════════════════════════════════════════════════════════\n";
    echo "  {$base}\n";
    echo "══════════════════════════════════════════════════════════════════\n";

    try {
        $resumen = filas($pdo, $base, 'SELECT
                (SELECT COUNT(*) FROM {B}.requisitos_matricula WHERE deleted_at IS NULL) AS pasos,
                (SELECT COUNT(DISTINCT year_id) FROM {B}.requisitos_matricula WHERE deleted_at IS NULL) AS anios,
                (SELECT COUNT(*) FROM {B}.requisitos_matricula
                   WHERE deleted_at IS NULL AND orden IS NOT NULL AND orden > 0) AS con_orden,
                (SELECT COUNT(*) FROM {B}.requisitos_matricula
                   WHERE deleted_at IS NULL AND editable_por_profe_id IS NOT NULL) AS con_dueno,
                (SELECT COUNT(*) FROM {B}.requisitos_alumno) AS marcas,
                (SELECT COUNT(DISTINCT alumno_id) FROM {B}.requisitos_alumno) AS alumnos,
                (SELECT MAX(updated_at) FROM {B}.requisitos_alumno) AS ultima_marca')[0];
    } catch (PDOException $e) {
        echo "  NO MEDIDO — {$e->getMessage()}\n";
        echo "  («no medido» y «cero» NO son lo mismo, y por eso esto no imprime 0.)\n";

        return;
    }

    printf("  pasos configurados (todos los años) ....... %s   en %s año(s)\n", $resumen['pasos'], $resumen['anios']);
    printf("  …con `orden` puesto (>0) ................. %s\n", $resumen['con_orden']);
    printf("  …con `editable_por_profe_id` (un dueño) .. %s\n", $resumen['con_dueno']);
    printf("  marcas por alumno ........................ %s   sobre %s alumno(s)\n", $resumen['marcas'], $resumen['alumnos']);
    printf("  última marca tocada ...................... %s\n", $resumen['ultima_marca'] ?? '(ninguna)');

    if ((int) $resumen['pasos'] === 0) {
        echo "\n  Este colegio NO usa los requisitos. No es un fallo: es la respuesta.\n";

        return;
    }

    echo "\n  ── en qué estado están las marcas ──\n";
    foreach (filas($pdo, $base, 'SELECT estado, COUNT(*) n FROM {B}.requisitos_alumno
            GROUP BY estado ORDER BY n DESC') as $f) {
        printf("     %-22s %s\n", $f['estado'], $f['n']);
    }

    echo "\n  ── los pasos, por año y en su orden ──\n";
    echo "     (es el recorrido que la fase 1 tendría que respetar, si lo hay)\n";
    foreach (filas($pdo, $base, 'SELECT y.year, r.orden, r.requisito,
                r.editable_por_profe_id AS dueno,
                (SELECT COUNT(*) FROM {B}.requisitos_alumno ra WHERE ra.requisito_id=r.id) AS marcas
            FROM {B}.requisitos_matricula r
            LEFT JOIN {B}.years y ON y.id=r.year_id
            WHERE r.deleted_at IS NULL
            ORDER BY y.year DESC, r.orden, r.id') as $f) {
        printf("     %-6s %3s  %-52s  dueño:%-5s marcas:%s\n",
            $f['year'] ?? '?', $f['orden'] ?? '-',
            mb_strimwidth((string) $f['requisito'], 0, 52, '…'),
            $f['dueno'] ?? '-', $f['marcas']);
    }
}

function imprimirCsv(PDO $pdo, string $base): void
{
    static $cabecera = false;

    if (! $cabecera) {
        echo "base,year,orden,requisito,dueno,marcas\n";
        $cabecera = true;
    }

    try {
        $fs = filas($pdo, $base, 'SELECT y.year, r.orden, r.requisito,
                r.editable_por_profe_id AS dueno,
                (SELECT COUNT(*) FROM {B}.requisitos_alumno ra WHERE ra.requisito_id=r.id) AS marcas
            FROM {B}.requisitos_matricula r
            LEFT JOIN {B}.years y ON y.id=r.year_id
            WHERE r.deleted_at IS NULL ORDER BY y.year DESC, r.orden, r.id');
    } catch (PDOException $e) {
        echo "{$base},NO_MEDIDO,,,,\n";

        return;
    }

    foreach ($fs as $f) {
        printf("%s,%s,%s,\"%s\",%s,%s\n", $base, $f['year'] ?? '', $f['orden'] ?? '',
            str_replace('"', '""', (string) $f['requisito']), $f['dueno'] ?? '', $f['marcas']);
    }
}
