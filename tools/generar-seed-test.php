<?php

/**
 * Genera database/dumps/test-seed.sql: una rebanada pequeña, anonimizada y
 * coherente de la base real, para que los tests de contrato tengan datos
 * contra los que golpear.
 *
 * Por qué una rebanada y no la base entera: la base real tiene 1,16 millones de
 * notas. Cargarla antes de cada tanda de tests es inviable, y meterla en el repo
 * es impensable — además son datos de menores.
 *
 * Cómo se recorta: se ancla en UN grupo de UN año y se sigue el grafo de claves
 * foráneas hacia fuera. Todo lo que entra tiene sus referencias dentro de la
 * rebanada, así que carga con las claves foráneas activas.
 *
 *   año → periodos → unidades → subunidades → notas
 *   año → grupos → asignaturas → unidades
 *                → matriculas → alumnos → parentescos → acudientes
 *
 * Anonimización: determinista a partir del id. Dos ejecuciones dan el mismo
 * fichero, así que el diff en git solo cambia si cambian los datos de verdad,
 * no en cada regeneración.
 *
 * Uso (dentro del contenedor, que es donde resuelve DB_HOST):
 *   docker exec 8myvc-app-1 php tools/generar-seed-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const SALIDA = __DIR__ . '/../database/dumps/test-seed.sql';

/*
 * El ancla. DOS años consecutivos con el mismo grupo de alumnos avanzando de
 * grado: Tercero 2024 (grupo 84, 56 alumnos) y Cuarto 2025 (98, los mismos 56
 * más 12).
 *
 * Con un año solo —como estaba hasta el 20 ago 2026— cuatro listas salían vacías
 * siempre y su contenido no lo comprobaba nadie: las tres de prematrículas y
 * matrículas, que buscan candidatos en el grado ANTERIOR con un `NOT IN` —la
 * consulta más enredada de las tres—, y `grupos/next-year`, que mira el
 * siguiente. El año de más las cubre las cuatro.
 *
 * **No se añade un tercer año hacia adelante, y se probó.** 2026 existe en
 * producción con sus trece grupos, pero está BORRADO, así que ninguna consulta
 * lo devuelve y meterlo en el seed no cambia nada. `grupos/next-year` se
 * comprueba al revés: con un token de 2024, que ve los grupos de 2025.
 *
 * Se pasan por argumento separados por coma si algún día hacen falta otros:
 *   php tools/generar-seed-test.php 7,8 84,98
 */
$YEARS  = numeros($argv[1] ?? '7,8');
$GRUPOS = numeros($argv[2] ?? '84,98');

function numeros(string $csv): array
{
    return array_values(array_filter(array_map('intval', explode(',', $csv))));
}

/*
 * Tablas que NO entran. Ruido de operación o datos sensibles que ningún test
 * necesita. `dis_libro_rojo` son anotaciones disciplinarias de menores: no
 * entra ni anonimizada.
 */
$OMITIDAS = [
    'debugging', 'bitacoras', 'dis_libro_rojo', 'password_reminders',
    'password_resets', 'jobs', 'migrations', 'historiales',
    // Tokens de sesión de Sanctum. Ruido de operación, y además credenciales:
    // no pintan nada en el repo. Se coló al regenerar el seed el 20 ago 2026
    // —la tabla no existía cuando se generó el anterior— y **rompió la carga**,
    // porque el esquema congelado es el de PRODUCCIÓN y allí la crea la
    // migración, que corre después del seed. Cualquier tabla nueva entra sola:
    // lo que no está aquí ni en los recortes se copia entero.
    'personal_access_tokens',
    // Peticiones de cambio: pequeñas, y cada fila es una copia de los datos
    // personales del alumno pendientes de aprobar. No las necesita ningún test P0.
    'change_asked', 'change_asked_data', 'change_asked_assignment',
    // Expedientes disciplinarios: descripcion, testigos y descargos son texto
    // libre lleno de nombres. Misma razón que dis_libro_rojo.
    'dis_procesos', 'dis_proceso_ordinales',
    // PIAR: planes de apoyo por discapacidad. El dato más sensible del sistema,
    // y ningún test de contrato lo necesita. Se queda fuera entero.
    'piars_alumnos', 'piars_grupos', 'piars_asignaturas', 'piars_actas_acuerdo',
];

/*
 * Columnas con datos personales. Se sustituyen por valores derivados del id.
 * Lo que no está aquí se copia tal cual, así que al añadir una tabla con datos
 * personales hay que añadirla aquí también.
 */
$ANONIMAS = [
    'alumnos'    => ['nombres', 'apellidos', 'documento', 'telefono', 'celular',
                     'direccion', 'barrio', 'email', 'facebook', 'eps',
                     'nro_sisben', 'nro_sisben_3', 'nee_descripcion', 'no_matricula',
                     // Creencia religiosa: dato sensible de por sí, y además hay
                     // una fila donde alguien escribió un nombre de pila.
                     'religion'],
    'acudientes' => ['nombres', 'apellidos', 'documento', 'telefono', 'celular',
                     'direccion', 'barrio', 'email', 'ocupacion'],
    'profesores' => ['nombres', 'apellidos', 'num_doc', 'telefono', 'celular',
                     'direccion', 'barrio', 'email', 'facebook', 'titulo'],
    'users'      => ['username', 'password', 'email'],
    'parentescos' => ['observaciones'],
    // Texto libre, y lleva dentro el nombre de OTRO alumno: «le pegó en la
    // cabeza al compañero <nombre y apellido>». Lo destapó el detector de fugas
    // al ampliar el seed al año anterior, el 20 ago 2026 — con un solo año esa
    // fila no entraba y la columna parecía inofensiva. Es la misma categoría que
    // `dis_libro_rojo`, que se omite entera; esta se anonimiza porque la tabla sí
    // la necesitan los recortes.
    'uniformes'  => ['descripcion'],
    // 'plancha' es el nombre del equipo, y lo forman con los nombres de pila.
    'vt_candidatos' => ['plancha'],
    // El nombre del fichero suele llevar el del alumno: 'foto-juan-perez.jpg'.
    'images'      => ['nombre'],
    // 'title' lleva cosas como 'Cumpleaños de <alumno>'; created_by_nombres es literal.
    'calendario'  => ['title', 'created_by_nombres'],
    // Datos médicos de menores en texto libre.
    'antecedentes' => ['observaciones', 'vac_cual', 'patol_cual', 'fami_cual'],
];

/*
 * Contraseña de todos los usuarios sembrados: 'test-1234'.
 *
 * El hash va fijo, no generado, para que dos ejecuciones del script den el
 * mismo fichero: bcrypt lleva sal aleatoria, y generarlo aquí haría que
 * cambiaran 2.351 líneas del diff en cada regeneración.
 *
 * Para cambiar la contraseña:
 *   php -r 'echo password_hash("la-nueva", PASSWORD_BCRYPT, ["cost" => 10]);'
 * y actualiza también CLAVE en tests/Contrato/CasoDeContrato.php.
 */
const HASH_TEST = '$2y$10$DH20aweU/E6X8p/zaVSKROI9AFxQbbqYqrcACkje.kxY6hbEDLsr.';

$NOMBRES   = ['Ana', 'Luis', 'Carmen', 'Jorge', 'Marta', 'Pedro', 'Sofia', 'Diego',
              'Elena', 'Raul', 'Clara', 'Hugo', 'Irene', 'Mateo', 'Nadia', 'Oscar'];
$APELLIDOS = ['Perez', 'Gomez', 'Rios', 'Vargas', 'Mora', 'Nieto', 'Cano', 'Duarte',
              'Salas', 'Bravo', 'Leon', 'Prieto', 'Cordero', 'Melo', 'Pinto', 'Rueda'];

function anonimizar(string $tabla, string $columna, $valor, int $id)
{
    global $NOMBRES, $APELLIDOS;

    // Un NULL sigue siendo NULL: la forma de la respuesta depende de ello.
    if ($valor === null) {
        return null;
    }

    switch ($columna) {
        case 'nombres':    return $NOMBRES[$id % count($NOMBRES)];
        case 'apellidos':  return $APELLIDOS[$id % count($APELLIDOS)] . ' ' . $APELLIDOS[($id * 7) % count($APELLIDOS)];
        case 'password':   return HASH_TEST;
        case 'username':   return $tabla . '_' . $id;
        case 'email':      return $tabla . $id . '@ejemplo.test';
        case 'facebook':   return 'https://ejemplo.test/' . $tabla . $id;
        case 'nombre':     return 'imagen-' . $id . '.jpg';
        case 'title':      return 'Evento ' . $id;
        case 'plancha':    return 'Plancha ' . $id;
        case 'created_by_nombres': return $NOMBRES[$id % count($NOMBRES)] . ' ' . $APELLIDOS[$id % count($APELLIDOS)];
        case 'telefono':   return '60' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
        case 'celular':    return '30' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
        case 'documento':
        case 'num_doc':    return (string) (1000000000 + $id);
        case 'no_matricula': return 'M' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        case 'direccion':  return 'Calle ' . ($id % 90 + 1) . ' # ' . ($id % 50 + 1) . '-' . ($id % 30 + 1);
        case 'barrio':     return 'Barrio ' . ($id % 20 + 1);
        case 'ocupacion':  return 'Ocupacion ' . ($id % 12 + 1);
        case 'titulo':     return 'Licenciatura ' . ($id % 8 + 1);
        case 'eps':        return 'EPS ' . ($id % 6 + 1);
        case 'religion':   return ['Credo A', 'Credo B', 'Credo C', 'Ninguna'][$id % 4];
        case 'nro_sisben':
        case 'nro_sisben_3': return (string) (2000000 + $id);
        default:           return 'texto ' . $id;   // observaciones, nee_descripcion
    }
}


// ---------------------------------------------------------------------------
// Detector de fugas
//
// La lista $ANONIMAS es una lista de columnas conocidas, y eso no basta: los
// nombres se cuelan en cualquier campo de texto libre. Ya pasó con
// `vt_candidatos.plancha` ('PAULINA - SOFIA - EMELY'), con los descargos de los
// procesos disciplinarios y con los informes PIAR.
//
// Esto compara, palabra a palabra, lo que se va a escribir contra los nombres y
// apellidos reales de la base. Si aparece uno, aborta.
// ---------------------------------------------------------------------------

/* Tablas donde una coincidencia es legítima: 'San Pablo' es una ciudad, no un alumno. */
const TABLAS_SIN_REVISAR = ['ciudades', 'paises'];

/*
 * Colisiones revisadas una a una y aceptadas: son palabras corrientes del
 * español que además resultan ser el nombre o apellido de alguien del colegio.
 * Se comprobó el texto de origen en cada caso.
 *
 * Aceptar es deliberado y explícito: cualquier palabra que NO esté aquí sigue
 * abortando la generación. Al añadir una, hay que mirar el texto de origen
 * primero — el detector solo sirve si el que lo silencia lo ha comprobado.
 */
const COLISIONES_ACEPTADAS = [
    // 'A partir del 2 de julio iniciamos labores del tercer periodo', y una cita
    // de Mandela. Ninguna nombra a un alumno.
    'publicaciones.contenido'      => ['JULIO', 'BELLO', 'ESPERANZA', 'NELSON'],
    // Lenguaje académico: 'contrasta diversas fuentes de informacion', 'cuadros
    // sinópticos'. Nada personal.
    //
    // Los seis de la segunda línea entraron con el año 2024: son el plan de
    // clase de religión —DAVID, JESÚS y MARCO(S) son de quien habla la unidad—,
    // 'feria de ciencia y tecnología', 'daily routines' de inglés, y CARLOS, que
    // no es nadie: sale de 'identifi carlos', una palabra partida a mano.
    'unidades.definicion'          => ['ELÍAS', 'URBANO', 'FUENTES', 'CUADROS',
                                       'DAILY', 'DAVID', 'FERIA', 'JESÚS', 'MARCO', 'CARLOS'],
    // El mes, en frases de boletín con fechas.
    'frases_asignatura.frase'      => ['ABRIL', 'JULIO'],
    // Igual que unidades: talleres de religión con sus personajes —SAMUEL,
    // DAVID, MARCOS, JESÚS, las tribus de ISRAEL, y BRENDA, del 'taller de Bruno
    // y Brenda' del libro—, más 'feria', 'largo' y el mes.
    'subunidades.definicion'       => ['ABRIL', 'FERIA', 'BRENDA', 'LARGO', 'SAMUEL',
                                       'JULIO', 'DAVID', 'ISRAEL', 'MARCOS', 'JESÚS'],
    'years.frase_final_certificado' => ['ABRIL'],
];

function palabrasPersonales(): array
{
    $filas = DB::select(
        'SELECT nombres n, apellidos a FROM alumnos
         UNION ALL SELECT nombres, apellidos FROM acudientes
         UNION ALL SELECT nombres, apellidos FROM profesores'
    );

    $palabras = [];

    foreach ($filas as $fila) {
        /*
         * Un campo de nombre con más de 60 caracteres no es un nombre. En la
         * base real hay una fila así: el acudiente 505 tiene 252 caracteres en
         * `apellidos` — una frase de boletín entera ('Se esfuerza por avanzar
         * cada día en su proceso académico, participa en clase...'), metida ahí
         * por algún formulario. Sin este filtro esa única fila mete veinte
         * palabras corrientes en la lista y el detector marca media base.
         */
        $nombre = trim(($fila->n ?? '') . ' ' . ($fila->a ?? ''));

        if (mb_strlen($nombre) > 60) {
            continue;
        }

        foreach (preg_split('/[^\p{L}]+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            // Cinco letras o más: por debajo hay demasiadas colisiones con
            // palabras corrientes ('Mora', 'Cruz', 'Alto') y el detector se
            // vuelve ruido que nadie mira.
            if (mb_strlen($palabra) >= 5) {
                $palabras[mb_strtoupper($palabra)] = true;
            }
        }
    }

    // Los nombres que inventa este mismo script no cuentan como fuga, aunque
    // coincidan con alguien real: es inevitable con nombres corrientes.
    global $NOMBRES, $APELLIDOS;
    foreach (array_merge($NOMBRES, $APELLIDOS) as $inventado) {
        unset($palabras[mb_strtoupper($inventado)]);
    }

    /*
     * Vocabulario del dominio que además es nombre de pila de alguien real.
     * Marcarlo sería ruido: aparece en frases de boletín, en nombres de rol y
     * en el nombre del propio colegio, no en un dato personal.
     */
    foreach (['CALLE', 'BARRIO', 'OCUPACION', 'LICENCIATURA', 'EVENTO', 'PLANCHA',
              'IMAGEN', 'TEXTO', 'EJEMPLO', 'CREDO', 'NINGUNA',
              'ALUMNO', 'PRUEBA', 'SIMÓN', 'BOLIVAR', 'ACADÉMICO'] as $comun) {
        unset($palabras[$comun]);
    }

    return $palabras;
}

function revisarFuga(array $palabrasPii, string $tabla, string $columna, $valor, array &$fugas): void
{
    if ($valor === null || in_array($tabla, TABLAS_SIN_REVISAR, true) || ! is_string($valor)) {
        return;
    }

    foreach (preg_split('/[^\p{L}]+/u', $valor, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
        $mayus = mb_strtoupper($palabra);

        if (mb_strlen($palabra) < 5 || ! isset($palabrasPii[$mayus])) {
            continue;
        }

        if (in_array($mayus, COLISIONES_ACEPTADAS[$tabla . '.' . $columna] ?? [], true)) {
            continue;
        }

        $fugas[$tabla . '.' . $columna][$mayus] = true;
    }
}

// ---------------------------------------------------------------------------
// Resolución de la rebanada. El orden importa: cada paso usa el anterior.
// ---------------------------------------------------------------------------

/**
 * Orden determinista para el volcado.
 *
 * Sin ORDER BY, MySQL devuelve las filas en el orden que le conviene, y no
 * tiene por qué ser el mismo dos veces. Eso hacía que regenerar el seed
 * produjera un diff de 2.354 líneas sin que cambiara un solo dato, y con eso
 * el fichero deja de ser revisable.
 *
 * Se ordena por la clave primaria; si la tabla no tiene (role_user es
 * user_id + role_id sin PK declarada), por todas sus columnas.
 */
function ordenEstable(string $tabla): string
{
    $pk = DB::select(
        "SELECT column_name c FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY'
         ORDER BY ordinal_position",
        [$tabla]
    );

    if ($pk === []) {
        $pk = DB::select(
            "SELECT column_name c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?
             ORDER BY ordinal_position",
            [$tabla]
        );
    }

    return implode(', ', array_map(fn ($f) => '`' . $f->c . '`', $pk));
}

function ids(string $sql, array $bind = []): array
{
    return array_map(fn ($f) => $f->id, DB::select($sql, $bind));
}

function lista(array $ids): string
{
    return $ids === [] ? 'NULL' : implode(',', array_map('intval', $ids));
}

echo 'Anclando en year_id IN ('.lista($YEARS).'), grupo_id IN ('.lista($GRUPOS).")\n";

$periodos    = ids('SELECT id FROM periodos WHERE year_id IN (' . lista($YEARS) . ')');
$grupos      = $GRUPOS;
$asignaturas = ids('SELECT id FROM asignaturas WHERE grupo_id IN (' . lista($GRUPOS) . ')');
$matriculas  = ids('SELECT id FROM matriculas WHERE grupo_id IN (' . lista($GRUPOS) . ')');
$alumnos     = ids('SELECT DISTINCT alumno_id id FROM matriculas WHERE grupo_id IN (' . lista($GRUPOS) . ')');
$parentescos = ids('SELECT id FROM parentescos WHERE alumno_id IN (' . lista($alumnos) . ')');
$acudientes  = ids('SELECT DISTINCT acudiente_id id FROM parentescos WHERE alumno_id IN (' . lista($alumnos) . ')');
$profesores  = ids('SELECT DISTINCT profesor_id id FROM asignaturas WHERE grupo_id IN (' . lista($GRUPOS) . ') AND profesor_id IS NOT NULL');
$unidades    = ids('SELECT id FROM unidades WHERE asignatura_id IN (' . lista($asignaturas) . ') AND periodo_id IN (' . lista($periodos) . ')');
$subunidades = ids('SELECT id FROM subunidades WHERE unidad_id IN (' . lista($unidades) . ')');

/*
 * Usuarios: los de la rebanada, más todos los de tipo 'Usuario' (son 20:
 * rector, secretaría, coordinación). Sin ellos no hay con qué probar el login
 * del cuarto tipo, y son pocos.
 */
$usuarios = ids(
    'SELECT user_id id FROM alumnos WHERE id IN (' . lista($alumnos) . ') AND user_id IS NOT NULL
     UNION SELECT user_id FROM acudientes WHERE id IN (' . lista($acudientes) . ') AND user_id IS NOT NULL
     UNION SELECT user_id FROM profesores WHERE id IN (' . lista($profesores) . ') AND user_id IS NOT NULL
     UNION SELECT id FROM users WHERE tipo = ?',
    ['Usuario']
);

/*
 * Imágenes referenciadas. Solo la fila de metadatos: el fichero en disco no
 * entra, y los tests de imágenes suben el suyo.
 */
$images = ids(
    'SELECT foto_id id FROM alumnos WHERE id IN (' . lista($alumnos) . ') AND foto_id IS NOT NULL
     UNION SELECT foto_id FROM acudientes WHERE id IN (' . lista($acudientes) . ') AND foto_id IS NOT NULL
     UNION SELECT foto_id FROM profesores WHERE id IN (' . lista($profesores) . ') AND foto_id IS NOT NULL
     UNION SELECT firma_id FROM profesores WHERE id IN (' . lista($profesores) . ') AND firma_id IS NOT NULL
     UNION SELECT logo_id FROM years WHERE logo_id IS NOT NULL
     UNION SELECT img_encabezado_id FROM years WHERE img_encabezado_id IS NOT NULL'
);

/*
 * Recortes. Lo que no aparezca aquí ni en \$OMITIDAS se copia entero: son
 * catálogos pequeños (materias, grados, roles, ciudades…) y da menos problemas
 * copiarlos que razonar si hacen falta.
 *
 * Se copian enteras a propósito, aunque parezcan recortables: `years` (9 filas),
 * `periodos` (34), `users` (2.351), `profesores` (51) e `images` (619). Media
 * base apunta a un año, un periodo, un usuario o una imagen; recortarlas obliga
 * a perseguir el cierre transitivo entero para no dejar referencias colgando, y
 * lo que se ahorra son unos cientos de kilobytes.
 */
$RECORTES = [
    'grupos'                      => 'id IN (' . lista($grupos) . ')',
    'asignaturas'                 => 'id IN (' . lista($asignaturas) . ')',
    'matriculas'                  => 'id IN (' . lista($matriculas) . ')',
    'alumnos'                     => 'id IN (' . lista($alumnos) . ')',
    'acudientes'                  => 'id IN (' . lista($acudientes) . ')',
    'parentescos'                 => 'id IN (' . lista($parentescos) . ')',
    'unidades'                    => 'id IN (' . lista($unidades) . ')',
    'subunidades'                 => 'id IN (' . lista($subunidades) . ')',
    'notas'                       => 'subunidad_id IN (' . lista($subunidades) . ') AND alumno_id IN (' . lista($alumnos) . ')',
    'notas_finales'               => 'alumno_id IN (' . lista($alumnos) . ') AND asignatura_id IN (' . lista($asignaturas) . ')',
    'ausencias'                   => 'alumno_id IN (' . lista($alumnos) . ') AND asignatura_id IN (' . lista($asignaturas) . ')',
    'frases_asignatura'           => 'alumno_id IN (' . lista($alumnos) . ') AND asignatura_id IN (' . lista($asignaturas) . ')',
    'nota_comportamiento'         => 'alumno_id IN (' . lista($alumnos) . ') AND periodo_id IN (' . lista($periodos) . ')',
    'recuperacion_final'          => 'alumno_id IN (' . lista($alumnos) . ')',
    'antecedentes'                => 'alumno_id IN (' . lista($alumnos) . ')',
    'contratos'                   => 'profesor_id IN (' . lista($profesores) . ') AND year_id IN (' . lista($YEARS) . ')',
    // Mismo filtro que nota_comportamiento, o quedan definiciones apuntando a
    // filas de otros periodos que la rebanada no incluye.
    'definiciones_comportamiento' => 'comportamiento_id IN (SELECT id FROM nota_comportamiento WHERE alumno_id IN (' . lista($alumnos) . ') AND periodo_id IN (' . lista($periodos) . '))',
    'uniformes'                   => 'alumno_id IN (' . lista($alumnos) . ') AND asignatura_id IN (' . lista($asignaturas) . ')',
    'frases_preescolar'           => 'asignatura_id IN (' . lista($asignaturas) . ')',
];

// ---------------------------------------------------------------------------
// Volcado
// ---------------------------------------------------------------------------

$pdo    = DB::connection()->getPdo();
$tablas = array_map(
    fn ($f) => array_values((array) $f)[0],
    DB::select('SHOW TABLES')
);
sort($tablas);

@mkdir(dirname(SALIDA), 0755, true);
$fh = fopen(SALIDA, 'w');

if ($fh === false) {
    fwrite(STDERR, "No se pudo abrir el fichero de salida\n");
    exit(1);
}

fwrite($fh, <<<CAB
-- Semilla de datos para los tests de contrato.
--
-- GENERADO. No editar a mano: `php tools/generar-seed-test.php`.
--
-- Es una rebanada anonimizada de la base real, anclada en un grupo de un año.
-- Los datos personales están sustituidos por valores derivados del id; nada de
-- lo que hay aquí identifica a nadie.
--
-- Contraseña de todos los usuarios: test-1234

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;


CAB);

$palabrasPii = palabrasPersonales();
printf("Detector de fugas: %d palabras personales reales\n", count($palabrasPii));

$fugas   = [];
$resumen = [];
$total   = 0;

foreach ($tablas as $tabla) {
    if (in_array($tabla, $OMITIDAS, true)) {
        continue;
    }

    $where = $RECORTES[$tabla] ?? '1=1';
    $filas = DB::select("SELECT * FROM `{$tabla}` WHERE {$where} ORDER BY " . ordenEstable($tabla));

    fwrite($fh, "TRUNCATE TABLE `{$tabla}`;\n");

    if ($filas === []) {
        fwrite($fh, "\n");
        continue;
    }

    $columnas = array_keys((array) $filas[0]);
    $listaCol = '`' . implode('`, `', $columnas) . '`';

    foreach (array_chunk($filas, 100) as $lote) {
        $valores = [];

        foreach ($lote as $fila) {
            $fila = (array) $fila;
            $id   = (int) ($fila['id'] ?? 0);
            $celdas = [];

            foreach ($columnas as $col) {
                $v = $fila[$col];

                if (in_array($col, $ANONIMAS[$tabla] ?? [], true)) {
                    $v = anonimizar($tabla, $col, $v, $id);
                }

                revisarFuga($palabrasPii, $tabla, $col, $v, $fugas);

                $celdas[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
            }

            $valores[] = '(' . implode(', ', $celdas) . ')';
        }

        fwrite($fh, "INSERT INTO `{$tabla}` ({$listaCol}) VALUES\n" . implode(",\n", $valores) . ";\n");
    }

    fwrite($fh, "\n");
    $resumen[$tabla] = count($filas);
    $total += count($filas);
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fh);

if ($fugas !== []) {
    fwrite(STDERR, "\n╔═ FUGA DE DATOS PERSONALES ═══════════════════════════════════\n");
    fwrite(STDERR, "║ Estas columnas llevan nombres reales al fichero generado.\n");
    fwrite(STDERR, "║ Añádelas a \$ANONIMAS, o la tabla entera a \$OMITIDAS.\n");
    fwrite(STDERR, "╚══════════════════════════════════════════════════════════════\n\n");

    foreach ($fugas as $donde => $palabras) {
        $muestra = array_slice(array_keys($palabras), 0, 5);
        fwrite(STDERR, sprintf("  %-40s %s%s\n", $donde, implode(', ', $muestra),
            count($palabras) > 5 ? ' … (' . count($palabras) . ')' : ''));
    }

    unlink(SALIDA);
    fwrite(STDERR, "\nSemilla NO escrita.\n");
    exit(1);
}

arsort($resumen);
echo "\nFilas por tabla (top 15):\n";
foreach (array_slice($resumen, 0, 15, true) as $t => $n) {
    printf("  %-30s %6d\n", $t, $n);
}
printf("\n%d tablas, %d filas, %s\n", count($resumen), $total, number_format(filesize(SALIDA) / 1024, 0) . ' KB');
printf("Escrito en %s\n", realpath(SALIDA));
