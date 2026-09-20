<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Le pide de verdad las doce rutas de `desempenos` a la aplicación, contra la base
 * de DESARROLLO y con dos tokens sacados del login real: el de `administrador` y
 * el de **un docente llano sin `can_edit_plantilla_notas`**.
 *
 * Existe por lo mismo que su hermano de `competencias`: leer el PHP no basta, y el
 * servidor web del contenedor sirve `/app`, que es el árbol principal, así que
 * desde un worktree no hay puerto por el que llamar. Esto levanta el kernel HTTP
 * **del worktree** y le mete peticiones por la misma puerta que nginx.
 *
 *   docker exec -w /app/.worktrees/f2 8myvc-app-1 php tools/probar-desempenos-en-el-docker.php
 *
 * ## El segundo usuario, y por qué hace falta crearlo
 *
 * **El candado de la D14 no se puede comprobar con `administrador`**: es
 * superusuario, así que `puedeEditarPlantillaNotas` lo deja pasar y los tres
 * caminos contestarían 200 estuviera el candado puesto o no. Hace falta alguien
 * **sin** el permiso, y de los usuarios reales de la base **no se conoce ninguna
 * contraseña**. Así que se crea uno, se usa y **se borra físicamente al final**,
 * junto con todo lo demás que esta prueba escriba.
 *
 * **Escribe en la base de desarrollo y deshace lo suyo**: se queda con los ids que
 * creó y los borra. Lo que ya existiera no lo toca. Y limpia de entrada cualquier
 * resto de una ejecución anterior que se hubiera muerto a medias.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

const USUARIO_DE_PRUEBA = 'docente-de-prueba-desempenos';
const CLAVE_DE_PRUEBA = 'prueba-desempenos-1234';

function pedirDesempenos(string $metodo, string $uri, array $cuerpo = [], ?string $token = null): array
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

function contarDesempenos(string $rotulo, array $r, $extra = null): void
{
    echo sprintf('  %-56s %d', $rotulo, $r['estado']).($extra === null ? '' : '   '.$extra).PHP_EOL;
}

/**
 * **Reintenta, porque `login/credentials` va con `throttle:login`.** Esta prueba
 * hace dos logins seguidos y se corre varias veces mientras se afina: el segundo
 * se come un 429 y lo que se ve es «FALLÓ», que no dice nada. Con el reintento y
 * el código impreso, un 429 se distingue de una contraseña mala.
 */
function tokenDesempenos(string $usuario, string $clave): ?string
{
    foreach ([0, 5, 15, 40] as $espera) {
        if ($espera > 0) {
            sleep($espera);
        }

        $r = pedirDesempenos('POST', '/api/login/credentials', ['username' => $usuario, 'password' => $clave]);

        if (isset($r['cuerpo']['el_token'])) {
            return $r['cuerpo']['el_token'];
        }

        if ($r['estado'] !== 429) {
            echo '        login de '.$usuario.': '.$r['estado'].' '
                .mb_substr(json_encode($r['cuerpo']) ?: '', 0, 120).PHP_EOL;

            return null;
        }

        echo '        login de '.$usuario.': 429, reintentando…'.PHP_EOL;
    }

    return null;
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

// ── Limpieza de entrada, por si una ejecución anterior murió a medias ─────────
DB::table('users')->where('username', USUARIO_DE_PRUEBA)->delete();

$tokenJefe = tokenDesempenos('administrador', 'patreongreat');

if ($tokenJefe === null) {
    echo 'Sin token de administrador no se puede seguir.'.PHP_EOL;
    exit(1);
}

echo '  token de administrador: ok'.PHP_EOL;

// ── El docente llano, creado a propósito ─────────────────────────────────────
$anio = DB::selectOne('SELECT id FROM years WHERE actual = 1 AND deleted_at IS NULL LIMIT 1');
$periodo = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND deleted_at IS NULL
                           ORDER BY numero, id LIMIT 1', [$anio->id]);

$usuarioId = DB::table('users')->insertGetId([
    'username' => USUARIO_DE_PRUEBA,
    'password' => Hash::make(CLAVE_DE_PRUEBA),
    'tipo' => 'Usuario',
    'is_active' => 1,
    'is_superuser' => 0,
    'periodo_id' => $periodo->id,
    'created_at' => now(),
    'updated_at' => now(),
]);

$tokenDocente = tokenDesempenos(USUARIO_DE_PRUEBA, CLAVE_DE_PRUEBA);
echo '  token del docente llano: '.($tokenDocente === null ? 'FALLÓ' : 'ok').PHP_EOL;

if ($tokenDocente === null) {
    DB::table('users')->where('id', $usuarioId)->delete();
    exit(1);
}

// ── Un caso limpio: grupo, asignatura y periodo abierto ──────────────────────
$molde = DB::selectOne('SELECT grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL
                        ORDER BY id LIMIT 1', [$anio->id]);

$grupoId = DB::table('grupos')->insertGetId([
    'nombre' => 'Grupo de prueba de desempeños', 'abrev' => 'PDE', 'year_id' => $anio->id,
    'grado_id' => $molde->grado_id, 'orden' => 96, 'created_at' => now(), 'updated_at' => now(),
]);

$materia = DB::selectOne('SELECT id, materia FROM materias WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
$profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

$asignaturaId = DB::table('asignaturas')->insertGetId([
    'materia_id' => $materia->id, 'grupo_id' => $grupoId, 'profesor_id' => $profesor->id,
    'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
]);

$abierto = (int) DB::table('periodos')->where('id', $periodo->id)->value('profes_pueden_editar_notas');
DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 1]);

$catalogoAntes = array_column(DB::select('SELECT id FROM desempenos_por_defecto'), 'id');
$filasAntes = array_column(DB::select('SELECT id FROM desempenos'), 'id');

echo PHP_EOL.'── El plan de área del colegio (siete rutas) ──'.PHP_EOL;

$r = pedirDesempenos('GET', '/api/desempenos/plantilla', [], $tokenJefe);
contarDesempenos('GET  desempenos/plantilla', $r, 'year_id='.($r['cuerpo']['year_id'] ?? '?')
    .' · filas='.count($r['cuerpo']['desempenos'] ?? []));

$r = pedirDesempenos('POST', '/api/desempenos/plantilla', [
    'materia_id' => $materia->id,
    'grado_id' => $molde->grado_id,
    'periodo_id' => $periodo->id,
    'competencia_id' => null,
    'tipo' => 'Saber hacer',
    'definicion' => 'Identifica los tipos de triángulo y los clasifica.',
], $tokenJefe);
contarDesempenos('POST desempenos/plantilla', $r, 'id='.($r['cuerpo']['id'] ?? '?').' · tipo='.($r['cuerpo']['tipo'] ?? '?'));
$delPlan = $r['cuerpo']['id'] ?? null;

$r = pedirDesempenos('POST', '/api/desempenos/plantilla', [
    'materia_id' => $materia->id,
    'grado_id' => null,
    'periodo_id' => $periodo->id,
    'definicion' => 'Para todos los grados: participa con respeto.',
], $tokenJefe);
contarDesempenos('POST desempenos/plantilla (grado NULL)', $r, 'id='.($r['cuerpo']['id'] ?? '?'));

$r = pedirDesempenos('PUT', '/api/desempenos/plantilla/'.$delPlan, ['definicion' => 'Corregido el texto.'], $tokenJefe);
contarDesempenos('PUT  desempenos/plantilla/{id}', $r, '«'.($r['cuerpo']['definicion'] ?? '?').'»');

$r = pedirDesempenos('PUT', '/api/desempenos/plantilla/orden', [
    'materia_id' => $materia->id, 'grado_id' => $molde->grado_id, 'periodo_id' => $periodo->id,
    'orden' => [$delPlan],
], $tokenJefe);
contarDesempenos('PUT  desempenos/plantilla/orden', $r, 'reordenados='.($r['cuerpo']['reordenados'] ?? '?'));

$r = pedirDesempenos('PUT', '/api/desempenos/plantilla/copiar', [
    'destino' => ['materia_id' => $materia->id, 'periodo_id' => $periodo->id],
    'origen' => ['tipo' => 'men'],
], $tokenJefe);
contarDesempenos('PUT  plantilla/copiar origen "men" (tiene que ser 422)', $r);
echo '        motivo: '.mb_substr((string) ($r['cuerpo']['message'] ?? ''), 0, 110).PHP_EOL;

$r = pedirDesempenos('PUT', '/api/desempenos/sembrar', [], $tokenJefe);
contarDesempenos('PUT  desempenos/sembrar', $r, json_encode($r['cuerpo']));

echo PHP_EOL.'── La planilla del docente (cinco rutas) ──'.PHP_EOL;

$r = pedirDesempenos('GET', "/api/desempenos?asignatura_id={$asignaturaId}&periodo_id={$periodo->id}", [], $tokenDocente);
contarDesempenos('GET  desempenos (docente llano)', $r, 'trae='.count($r['cuerpo']['desempenos'] ?? []));

$sembrados = array_values(array_filter(
    $r['cuerpo']['desempenos'] ?? [],
    fn ($d) => (int) $d['por_defecto'] === 1
));
$delColegio = $sembrados[0]['id'] ?? null;

echo '        por_defecto=1 sembrados: '.count($sembrados).PHP_EOL;

$r = pedirDesempenos('POST', '/api/desempenos', [
    'asignatura_id' => $asignaturaId,
    'periodo_id' => $periodo->id,
    'definicion' => 'El que añade el docente porque al área se le olvidó.',
], $tokenDocente);
contarDesempenos('POST desempenos (el suyo)', $r, 'id='.($r['cuerpo']['id'] ?? '?')
    .' · por_defecto='.($r['cuerpo']['por_defecto'] ?? '?'));
$suyo = $r['cuerpo']['id'] ?? null;

$r = pedirDesempenos('PUT', '/api/desempenos/'.$suyo, ['definicion' => 'Con otro texto.'], $tokenDocente);
contarDesempenos('PUT  desempenos/{id} (el suyo)', $r);

echo PHP_EOL.'── El candado de la D14: los TRES caminos, con el docente llano ──'.PHP_EOL;

$r = pedirDesempenos('PUT', '/api/desempenos/'.$delColegio, ['definicion' => 'Se lo cambio yo.'], $tokenDocente);
contarDesempenos('PUT    el del colegio  (tiene que ser 403)', $r);

$r = pedirDesempenos('DELETE', '/api/desempenos/'.$delColegio, [], $tokenDocente);
contarDesempenos('DELETE el del colegio  (tiene que ser 403)', $r);

/*
 * **La lista de `orden` tiene que traer TODOS los vivos del grupo**, así que se
 * lee la planilla otra vez en vez de escribir dos ids a mano: con una lista
 * parcial lo que contesta es 422 —que también está bien— y no se vería el candado,
 * que es lo que esta prueba viene a mirar.
 */
$planilla = pedirDesempenos('GET', "/api/desempenos?asignatura_id={$asignaturaId}&periodo_id={$periodo->id}", [], $tokenDocente);
$enOrden = array_column($planilla['cuerpo']['desempenos'] ?? [], 'id');
$movidos = $enOrden;
[$movidos[0], $movidos[count($movidos) - 1]] = [$movidos[count($movidos) - 1], $movidos[0]];

$r = pedirDesempenos('PUT', '/api/desempenos/orden', [
    'asignatura_id' => $asignaturaId, 'periodo_id' => $periodo->id,
    'orden' => $movidos,
], $tokenDocente);
contarDesempenos('PUT    orden moviéndolo (tiene que ser 403)', $r);

$vivo = DB::selectOne('SELECT por_defecto, deleted_at, definicion FROM desempenos WHERE id = ?', [$delColegio]);
echo '        y la fila del colegio sigue: por_defecto='.$vivo->por_defecto
    .' · borrada='.($vivo->deleted_at === null ? 'no' : 'SÍ').PHP_EOL;

$r = pedirDesempenos('PUT', '/api/desempenos/orden', [
    'asignatura_id' => $asignaturaId, 'periodo_id' => $periodo->id,
    'orden' => $enOrden,
], $tokenDocente);
contarDesempenos('PUT    orden SIN moverlo (tiene que ser 200)', $r, 'reordenados='.($r['cuerpo']['reordenados'] ?? '?'));

$r = pedirDesempenos('PUT', '/api/desempenos/'.$delColegio, [
    'definicion' => $vivo->definicion,
], $tokenDocente);
contarDesempenos('PUT    guardar sin cambiar nada (tiene que ser 200)', $r);

echo PHP_EOL.'── El periodo cerrado, que lo está para todos ──'.PHP_EOL;
DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => 0]);

$r = pedirDesempenos('PUT', '/api/desempenos/'.$suyo, ['definicion' => 'No.'], $tokenDocente);
contarDesempenos('PUT    el suyo, periodo cerrado (403)', $r);

$r = pedirDesempenos('PUT', '/api/desempenos/'.$suyo, ['definicion' => 'Tampoco.'], $tokenJefe);
contarDesempenos('PUT    el suyo, periodo cerrado, CON permiso (403)', $r);

// ── Deshacer todo lo que esta prueba escribió ────────────────────────────────
DB::table('periodos')->where('id', $periodo->id)->update(['profes_pueden_editar_notas' => $abierto]);

$mios = array_values(array_diff(array_column(DB::select('SELECT id FROM desempenos'), 'id'), $filasAntes));
if ($mios !== []) {
    DB::table('desempenos')->whereIn('id', $mios)->delete();
}

$miosCatalogo = array_values(array_diff(
    array_column(DB::select('SELECT id FROM desempenos_por_defecto'), 'id'), $catalogoAntes
));
if ($miosCatalogo !== []) {
    DB::table('desempenos_por_defecto')->whereIn('id', $miosCatalogo)->delete();
}

DB::table('asignaturas')->where('id', $asignaturaId)->delete();
DB::table('grupos')->where('id', $grupoId)->delete();
DB::table('personal_access_tokens')->where('tokenable_id', $usuarioId)->delete();
DB::table('users')->where('id', $usuarioId)->delete();

echo PHP_EOL.'limpieza: '.count($mios).' desempeños, '.count($miosCatalogo).' filas de plan de área, '
    .'1 grupo, 1 asignatura y 1 usuario borrados. Quedan '
    .DB::table('desempenos')->count().' desempeños y '
    .DB::table('desempenos_por_defecto')->count().' del plan de área en la base.'.PHP_EOL;
