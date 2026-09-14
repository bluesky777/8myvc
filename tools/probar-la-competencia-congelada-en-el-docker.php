<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Le pide de verdad a la aplicación **la cuarta columna**: que `PUT desempenos/rejilla`
 * congele el texto de la competencia y que el boletín por competencias imprima **el
 * congelado** y no el de hoy.
 *
 * Es la hermana de `probar-el-boletin-por-competencias-en-el-docker.php` y existe por lo
 * mismo: leer el PHP no basta, y el servidor web del contenedor sirve `/app` —el árbol
 * principal—, así que desde un worktree no hay puerto por el que llamar. Esto levanta el
 * kernel HTTP **del worktree** y le mete peticiones por la misma puerta que nginx.
 *
 *   docker exec -w /app/.worktrees/comp 8myvc-app-1 \
 *       php tools/probar-la-competencia-congelada-en-el-docker.php
 *
 * > **NO se corre mientras haya una tanda de tests contra esa base**, ni al revés. Esto
 * > escribe —dentro de una transacción, pero escribe—, y una herramienta de `tools/` que
 * > deja rastro en una base que otro está leyendo pone en rojo un test de otro, lejísimos
 * > de la causa. Costó dos tandas la noche del 13 sep 2026 (03-tests.md, «Una herramienta
 * > de `tools/` contra la base de TESTS deja rastro»). El paso 1 de aquella sección
 * > —`pgrep -af phpunit`— vale en las dos direcciones.
 *
 * ## Lo que escribe, y lo que NO toca
 *
 * Crea **una competencia y un desempeño suyos** y marca **una casilla** por la ruta, todo
 * dentro de una transacción que se revierte siempre, incluso si algo revienta.
 *
 * **No toca nada de lo que hay sembrado** —la competencia 93 con sus desempeños 21, 22 y
 * 25, y sus marcas vivas—: las lee para el censo y las deja como están. Renombrar algo
 * ajeno para ver el efecto sería romper la pantalla que otro está conduciendo en Chrome.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

/** @return array{estado: int, cuerpo: mixed} */
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

function fallar(string $porque): void
{
    echo PHP_EOL.'  FALLO: '.$porque.PHP_EOL;
    exit(1);
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

/*
 * ── 1. El censo, ANTES de escribir nada ────────────────────────────────────────
 *
 * La migración es **anulable y sin back-fill**, así que lo que ya estaba tiene que
 * seguir con `competencia` a `NULL`. Que la cifra de «sin congelar» sea **el total**
 * es lo que demuestra que la migración no se inventó ni un dato.
 */
$censo = DB::selectOne(
    'SELECT COUNT(*) AS celdas, SUM(competencia IS NULL) AS sin_congelar
       FROM frases_asignatura WHERE desempeno_id IS NOT NULL AND deleted_at IS NULL'
);

echo '1. censo de la rejilla, antes de tocar nada'.PHP_EOL;
echo '   celdas vivas: '.$censo->celdas.'   ·   sin congelar: '.(int) $censo->sin_congelar.PHP_EOL;
echo '   (las de antes de la migración no la tendrán nunca: el boletín las imprime con el texto vivo)'.PHP_EOL.PHP_EOL;

/*
 * ── 2. El sujeto ───────────────────────────────────────────────────────────────
 *
 * Se **pregunta** por el usuario antes de intentar el login, porque un login fallido
 * ESCRIBE: `Services\Login` anota el intento en `auditoria` y en `bitacoras`, y esas dos
 * filas sobreviven a la prueba.
 */
if (DB::selectOne('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL', ['administrador']) === null) {
    fallar('esta base no tiene `administrador`. Esto va contra la base de DESARROLLO, no contra una de tests.');
}

$login = pedir('POST', '/api/login/credentials', ['username' => 'administrador', 'password' => 'patreongreat']);

if (! isset($login['cuerpo']['el_token'])) {
    fallar('login '.$login['estado'].': '.mb_substr(json_encode($login['cuerpo']) ?: '', 0, 200));
}

$token = $login['cuerpo']['el_token'];

$yo = DB::selectOne('SELECT periodo_id FROM users WHERE username = ?', ['administrador']);
$periodo = DB::selectOne('SELECT id, numero, year_id FROM periodos WHERE id = ?', [$yo->periodo_id]);

echo '2. sujeto: administrador · periodo '.$periodo->id.' (nº '.$periodo->numero.') · año '.$periodo->year_id.PHP_EOL;

/*
 * La asignatura se busca **con celdas de rejilla ya puestas Y colgadas de una
 * competencia**, y las dos condiciones hacen falta:
 *
 *   - sin celdas, el boletín sale en 200 y sin nada dentro, o sea que pasaría sin
 *     haber mirado nada;
 *   - y sin competencia, el paso 5 —que las filas de antes imprimen el texto vivo— no
 *     tendría **ni una fila que contar**, que es la forma de dar un ✓ vacío.
 */
$asignatura = DB::selectOne(
    'SELECT a.id, a.grupo_id, a.materia_id, g.grado_id, g.year_id
       FROM frases_asignatura fa
       INNER JOIN desempenos d ON d.id = fa.desempeno_id AND d.deleted_at IS NULL
       INNER JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
       INNER JOIN asignaturas a ON a.id = fa.asignatura_id AND a.deleted_at IS NULL
       INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
      WHERE fa.desempeno_id IS NOT NULL AND fa.deleted_at IS NULL
        AND fa.periodo_id = ? AND g.year_id = ?
      ORDER BY a.id LIMIT 1',
    [$periodo->id, $periodo->year_id]
);

if ($asignatura === null) {
    fallar('no hay ninguna asignatura con celdas de rejilla colgadas de una competencia '
        .'en el periodo '.$periodo->id.'. Sin eso esta prueba daría verde sin mirar nada.');
}

$alumno = DB::selectOne(
    'SELECT fa.alumno_id FROM frases_asignatura fa
       INNER JOIN desempenos d ON d.id = fa.desempeno_id AND d.deleted_at IS NULL
       INNER JOIN competencias c ON c.id = d.competencia_id AND c.deleted_at IS NULL
      WHERE fa.asignatura_id = ? AND fa.periodo_id = ? AND fa.desempeno_id IS NOT NULL
        AND fa.deleted_at IS NULL ORDER BY fa.id LIMIT 1',
    [$asignatura->id, $periodo->id]
);

echo '   asignatura '.$asignatura->id.' · grupo '.$asignatura->grupo_id.' · alumno '.$alumno->alumno_id.PHP_EOL.PHP_EOL;

/*
 * ── 3. Todo lo que escribe va dentro de una transacción que se revierte ────────
 */
DB::beginTransaction();

register_shutdown_function(function () {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
        echo PHP_EOL.'  (todo revertido: la base queda como estaba)'.PHP_EOL;
    }
});

const TEXTO_DE_ANTES = 'Como se llamaba la competencia el día que se marcó la casilla';
const TEXTO_DE_DESPUES = 'Como la reescribió el colegio dos años después';

$competencia = (int) DB::table('competencias')->insertGetId([
    'year_id' => $asignatura->year_id,
    'materia_id' => $asignatura->materia_id,
    'grado_id' => $asignatura->grado_id,
    'definicion' => TEXTO_DE_ANTES,
    'orden' => 990,
    'created_at' => now(), 'updated_at' => now(),
]);

$desempeno = (int) DB::table('desempenos')->insertGetId([
    'asignatura_id' => $asignatura->id,
    'periodo_id' => $periodo->id,
    'competencia_id' => $competencia,
    'definicion' => 'El desempeño que esta prueba marca y deshace',
    'orden' => 990,
    'por_defecto' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);

$suelto = (int) DB::table('desempenos')->insertGetId([
    'asignatura_id' => $asignatura->id,
    'periodo_id' => $periodo->id,
    'competencia_id' => null,
    'definicion' => 'Y uno suelto, que no cuelga de ninguna competencia (D10)',
    'orden' => 991,
    'por_defecto' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);

$nivel = DB::selectOne(
    'SELECT id, desempenio FROM escalas_de_valoracion
      WHERE year_id = ? AND deleted_at IS NULL ORDER BY orden DESC, id DESC LIMIT 1',
    [$asignatura->year_id]
);

if ($nivel === null) {
    fallar('el año '.$asignatura->year_id.' no tiene escala de valoración.');
}

/*
 * ── 4. La ESCRITURA ───────────────────────────────────────────────────────────
 */
$r = pedir('PUT', '/api/desempenos/rejilla', [
    'asignatura_id' => (int) $asignatura->id,
    'periodo_id' => (int) $periodo->id,
    'celdas' => [
        ['alumno_id' => (int) $alumno->alumno_id, 'desempeno_id' => $desempeno, 'escala_id' => (int) $nivel->id],
        ['alumno_id' => (int) $alumno->alumno_id, 'desempeno_id' => $suelto, 'escala_id' => (int) $nivel->id],
    ],
], $token);

echo '3. PUT desempenos/rejilla → '.$r['estado'].'   '.json_encode($r['cuerpo']).PHP_EOL;

if ($r['estado'] !== 200) {
    fallar('la rejilla no guardó.');
}

$celdas = DB::select(
    'SELECT desempeno_id, nivel, competencia FROM frases_asignatura
      WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ?
        AND desempeno_id IN (?, ?) AND deleted_at IS NULL ORDER BY desempeno_id',
    [$alumno->alumno_id, $asignatura->id, $periodo->id, $desempeno, $suelto]
);

foreach ($celdas as $celda) {
    echo '   desempeño '.$celda->desempeno_id.'  nivel='.var_export($celda->nivel, true)
        .'  competencia='.var_export($celda->competencia, true).PHP_EOL;
}

$conCompetencia = null;
$sinCompetencia = null;

foreach ($celdas as $celda) {
    if ((int) $celda->desempeno_id === $desempeno) {
        $conCompetencia = $celda->competencia;
    }
    if ((int) $celda->desempeno_id === $suelto) {
        $sinCompetencia = $celda->competencia;
    }
}

if ($conCompetencia !== TEXTO_DE_ANTES) {
    fallar('la celda no congeló el texto de su competencia: '.var_export($conCompetencia, true));
}

if ($sinCompetencia !== null) {
    fallar('el desempeño suelto dejó algo en `competencia` y tenía que quedar a null: '
        .var_export($sinCompetencia, true));
}

echo '   ✓ congelada la que tiene competencia, y `null` limpio la que no (D10)'.PHP_EOL.PHP_EOL;

/*
 * ── 5. El renombrado, y la LECTURA ────────────────────────────────────────────
 */
DB::table('competencias')->where('id', $competencia)->update(['definicion' => TEXTO_DE_DESPUES]);

echo '4. el colegio renombra la competencia → '.TEXTO_DE_DESPUES.PHP_EOL;

$r = pedir('PUT', '/api/boletines-competencias/detailed-notas/'.$asignatura->grupo_id, [
    'periodo_a_calcular' => (int) $periodo->numero,
    'requested_alumnos' => [['alumno_id' => (int) $alumno->alumno_id, 'grupo_id' => (int) $asignatura->grupo_id]],
], $token);

echo '   PUT boletines-competencias/detailed-notas/'.$asignatura->grupo_id.' → '.$r['estado'].PHP_EOL;

if ($r['estado'] !== 200) {
    fallar('el boletín no contestó 200: '.mb_substr(json_encode($r['cuerpo']) ?: '', 0, 300));
}

$bloque = null;
$vivos = [];

foreach ($r['cuerpo'][2] as $unAlumno) {
    if ((int) $unAlumno['alumno_id'] !== (int) $alumno->alumno_id) {
        continue;
    }

    foreach ($unAlumno['asignaturas'] as $unaAsignatura) {
        if ((int) $unaAsignatura['asignatura_id'] !== (int) $asignatura->id) {
            continue;
        }

        foreach ($unaAsignatura['competencias'] as $unBloque) {
            if ((int) $unBloque['competencia_id'] === $competencia) {
                $bloque = $unBloque;
            } else {
                $vivos[(int) $unBloque['competencia_id']] = $unBloque['definicion'];
            }
        }
    }
}

if ($bloque === null) {
    fallar('el boletín no trae el bloque de la competencia '.$competencia.'.');
}

echo '   cabecera impresa: '.var_export($bloque['definicion'], true).PHP_EOL;

if ($bloque['definicion'] !== TEXTO_DE_ANTES) {
    fallar('el boletín imprimió el texto de HOY. La cuarta columna no está ganando.');
}

echo '   ✓ el boletín imprime lo que decía el papel, no lo que dice el catálogo hoy'.PHP_EOL.PHP_EOL;

/*
 * ── 6. Y la otra mitad: las filas de antes siguen imprimiendo el texto vivo ───
 */
echo '5. los bloques de las celdas que ya estaban (competencia a `null`): '.count($vivos).PHP_EOL;

if ($vivos === []) {
    fallar('ni un bloque de los de antes. Un ✓ sobre cero filas no dice nada: '
        .'la regla de la casa es que `tools/` no imprime OK sin decir su población.');
}

foreach ($vivos as $id => $texto) {
    $hoy = DB::table('competencias')->where('id', $id)->value('definicion');
    echo '   competencia '.$id.': impreso '.var_export(mb_substr((string) $texto, 0, 60), true).PHP_EOL;

    if ((string) $texto !== (string) $hoy) {
        fallar('una fila sin copia congelada tiene que imprimir el texto vivo, y no coincide.');
    }
}

echo '   ✓ sin copia no hay nada que preferir: imprimen el vivo, que es lo único que hay'.PHP_EOL;
