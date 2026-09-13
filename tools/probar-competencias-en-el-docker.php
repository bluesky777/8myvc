<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Le pide de verdad las siete rutas de `competencias` a la aplicación, contra la
 * base de DESARROLLO y con un token de `administrador` sacado del login real.
 *
 * Existe porque leer el SQL y el PHP no basta y porque el servidor web del
 * contenedor sirve `/app`, que es el árbol principal: desde un worktree no hay
 * puerto por el que llamar. Esto levanta el kernel HTTP **del worktree** y le
 * mete peticiones por la misma puerta que nginx —guards, middlewares y base
 * incluidos—, así que lo que sale es la respuesta de verdad.
 *
 *   docker exec -w /app/.worktrees/f2 8myvc-app-1 php tools/probar-competencias-en-el-docker.php
 *
 * **Escribe en la base de desarrollo** (adopta competencias del MEN) y **deshace
 * lo suyo al final**: se queda con los ids que creó y los borra físicamente. Lo
 * que no puede deshacer —una fila que ya existiera— no lo toca.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function pedir(string $metodo, string $uri, array $cuerpo = [], ?string $token = null): array
{
    global $kernel;

    $peticion = Request::create(
        $uri,
        $metodo,
        $metodo === 'GET' ? [] : [],
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

function contar(string $rotulo, array $r, $extra = null): void
{
    $texto = sprintf('  %-58s %d', $rotulo, $r['estado']);

    if ($extra !== null) {
        $texto .= '   '.$extra;
    }

    echo $texto.PHP_EOL;
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

// ── 1. Login de verdad ───────────────────────────────────────────────────────
$login = pedir('POST', '/api/login/credentials', [
    'username' => 'administrador',
    'password' => 'patreongreat',
]);
$token = $login['cuerpo']['el_token'] ?? null;

contar('POST login (administrador)', $login, $token === null ? 'SIN TOKEN' : 'token ok');

if ($token === null) {
    echo PHP_EOL.'Sin token no se puede seguir. Respuesta: '.json_encode($login['cuerpo']).PHP_EOL;
    exit(1);
}

$materia = DB::selectOne(
    "SELECT id, materia FROM materias WHERE materia LIKE 'LENGUA CASTELLANA%' AND deleted_at IS NULL LIMIT 1"
);
$religion = DB::selectOne(
    "SELECT id, materia FROM materias WHERE materia LIKE 'EDUCACI%N RELIGIOSA%' AND deleted_at IS NULL LIMIT 1"
);
$matematicas = DB::selectOne(
    "SELECT id, materia FROM materias WHERE materia LIKE 'MATEM%' AND deleted_at IS NULL LIMIT 1"
);
$sexto = DB::selectOne("SELECT id FROM grados WHERE nombre = 'Sexto' LIMIT 1");
$transicion = DB::selectOne("SELECT id FROM grados WHERE nombre = 'Transición' LIMIT 1");

$antes = array_column(DB::select('SELECT id FROM competencias'), 'id');

echo PHP_EOL.'── Las siete rutas ──'.PHP_EOL;

// ── 2. GET competencias ──────────────────────────────────────────────────────
$r = pedir('GET', '/api/competencias', [], $token);
contar('GET  competencias', $r, 'year_id='.($r['cuerpo']['year_id'] ?? '?')
    .' · filas='.count($r['cuerpo']['competencias'] ?? []));

// ── 3. GET competencias/catalogo-men ─────────────────────────────────────────
$r = pedir('GET', "/api/competencias/catalogo-men?materia_id={$materia->id}&grado_id={$sexto->id}", [], $token);
contar('GET  catalogo-men · '.$materia->materia, $r,
    'area='.($r['cuerpo']['area_men']['clave'] ?? 'null')
    .' · conjunto='.($r['cuerpo']['conjunto'] ?? 'null')
    .' · trae='.count($r['cuerpo']['competencias'] ?? [])
    .' · adoptables='.($r['cuerpo']['adoptables'] ?? '?'));

$r = pedir('GET', "/api/competencias/catalogo-men?materia_id={$matematicas->id}&grado_id={$sexto->id}", [], $token);
contar('GET  catalogo-men · '.$matematicas->materia, $r,
    'por_tipo='.json_encode($r['cuerpo']['por_tipo'] ?? null)
    .' · adoptables='.($r['cuerpo']['adoptables'] ?? '?'));

$r = pedir('GET', "/api/competencias/catalogo-men?materia_id={$religion->id}&grado_id={$sexto->id}", [], $token);
contar('GET  catalogo-men · '.$religion->materia, $r,
    'cubierta='.var_export($r['cuerpo']['cubierta'] ?? null, true)
    .' · '.($r['cuerpo']['emparejamiento']['materia'] ?? '?'));
echo '        motivo: '.($r['cuerpo']['motivo'] ?? 'null').PHP_EOL;

$r = pedir('GET', "/api/competencias/catalogo-men?materia_id={$matematicas->id}&grado_id={$transicion->id}", [], $token);
contar('GET  catalogo-men · Transición', $r, $r['cuerpo']['emparejamiento']['grado'] ?? '?');
echo '        motivo: '.($r['cuerpo']['motivo'] ?? 'null').PHP_EOL;

$r = pedir('GET', '/api/competencias/catalogo-men', [], $token);
contar('GET  catalogo-men SIN materia_id (tiene que ser 422)', $r);

// ── 4. PUT competencias/copiar con origen MEN ────────────────────────────────
$r = pedir('PUT', '/api/competencias/copiar', [
    'destino' => ['materia_id' => $materia->id, 'grado_id' => $sexto->id],
    'origen' => ['tipo' => 'men'],
], $token);
contar('PUT  copiar · origen men (1.ª vez)', $r, json_encode($r['cuerpo']));

$r = pedir('PUT', '/api/competencias/copiar', [
    'destino' => ['materia_id' => $materia->id, 'grado_id' => $sexto->id],
    'origen' => ['tipo' => 'men'],
], $token);
contar('PUT  copiar · origen men (2.ª vez)', $r,
    'copiadas='.($r['cuerpo']['copiadas'] ?? '?')
    .' · duplicadas='.($r['cuerpo']['saltadas_por_duplicado'] ?? '?'));

// ── 5. POST / PUT / DELETE / orden ───────────────────────────────────────────
$r = pedir('POST', '/api/competencias', [
    'materia_id' => $materia->id,
    'grado_id' => $sexto->id,
    'definicion' => 'Competencia escrita a mano por la prueba del docker.',
], $token);
contar('POST competencias', $r, 'id='.($r['cuerpo']['id'] ?? '?')
    .' · grupo='.($r['cuerpo']['grupo']['competencias'] ?? '?'));
$nueva = $r['cuerpo']['id'] ?? null;

$r = pedir('PUT', '/api/competencias/'.$nueva, ['definicion' => 'Y ahora con otro texto.'], $token);
contar('PUT  competencias/{id}', $r, '«'.($r['cuerpo']['definicion'] ?? '?').'»');

$grupo = pedir('GET', "/api/competencias?materia_id={$materia->id}&grado_id={$sexto->id}", [], $token);
$ids = array_column($grupo['cuerpo']['competencias'] ?? [], 'id');
$r = pedir('PUT', '/api/competencias/orden', [
    'materia_id' => $materia->id, 'grado_id' => $sexto->id, 'orden' => array_reverse($ids),
], $token);
contar('PUT  competencias/orden', $r, 'reordenadas='.($r['cuerpo']['reordenadas'] ?? '?'));

$r = pedir('PUT', '/api/competencias/orden', [
    'materia_id' => $materia->id, 'grado_id' => $sexto->id, 'orden' => [$nueva],
], $token);
contar('PUT  orden con lista parcial (tiene que ser 422)', $r);

$r = pedir('DELETE', '/api/competencias/'.$nueva, [], $token);
contar('DEL  competencias/{id}', $r, 'id='.($r['cuerpo']['id'] ?? '?'));

// ── 6. Un docente llano ──────────────────────────────────────────────────────
$docente = DB::selectOne(
    'SELECT u.username FROM users u
       JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
      WHERE u.is_superuser = 0 AND u.is_active = 1 AND u.deleted_at IS NULL
      ORDER BY u.id LIMIT 1'
);

echo PHP_EOL.'── El docente llano ('.($docente->username ?? 'ninguno').') ──'.PHP_EOL;

if ($docente !== null) {
    echo '  (sin contraseña conocida: se comprueba con el guard, no por login)'.PHP_EOL;
}

// ── 7. Deshacer lo que esta prueba escribió ──────────────────────────────────
$despues = array_column(DB::select('SELECT id FROM competencias'), 'id');
$mias = array_values(array_diff($despues, $antes));

if ($mias !== []) {
    DB::table('competencias')->whereIn('id', $mias)->delete();
}

echo PHP_EOL.'limpieza: '.count($mias).' filas creadas por esta prueba, borradas. '
    .'Quedan '.DB::table('competencias')->count().' en la base.'.PHP_EOL;
