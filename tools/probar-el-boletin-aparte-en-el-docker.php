<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Le pide de verdad las tres rutas del boletín aparte que toca la **Entrega 4** (D18)
 * —`copiar`, `periodo` y `planilla`— contra la base de DESARROLLO y con el token real
 * de `administrador`.
 *
 * Existe por lo mismo que sus hermanas `probar-competencias-*`, `probar-desempenos-*` y
 * `probar-el-boletin-por-competencias-*`: leer el PHP no basta —13 sep 2026, «Leer el
 * SQL de 8myvc no basta»— y el servidor web del contenedor sirve `/app`, o sea el árbol
 * principal, así que desde un worktree no hay puerto por el que llamar. Esto levanta el
 * kernel HTTP **del worktree** y le mete peticiones por la misma puerta que nginx.
 *
 *   docker exec -w /app/.worktrees/bi 8myvc-app-1 php tools/probar-el-boletin-aparte-en-el-docker.php
 *
 * > **NO se corre mientras haya una tanda de tests contra esa base**, ni al revés. Esto
 * > ESCRIBE —marca a un alumno, le siembra la rejilla, inserta filas de plantilla— y
 * > aunque lo deshaga todo, durante unos segundos las filas están ahí y cualquier
 * > barrido de superficie las ve. Costó dos tandas enteras la noche del 13 sep 2026
 * > (03-tests.md, «Una herramienta de `tools/` contra la base de TESTS deja rastro»).
 *
 * ## Todo dentro de UNA transacción que se revierte siempre
 *
 * La base de desarrollo la comparten varias sesiones. `DB::beginTransaction()` al
 * principio y `DB::rollBack()` en un `finally` **y** en un `register_shutdown_function`,
 * porque un `abort()` de Laravel dentro del kernel no lanza una excepción que este
 * fichero pueda ver.
 *
 * ## La plantilla del colegio está VACÍA en `simonbolivar` (medido el 13 sep 2026)
 *
 * `unidades_por_defecto`: **0 filas**. `subunidades_por_defecto`: **0 filas**. O sea que
 * el tercer origen contra esta base copiaría cero unidades y el 200 no diría nada. Por
 * eso esta herramienta **siembra sus propias filas de plantilla dentro de la
 * transacción**, con las dos gradas que hacen falta para ver la precedencia —una general
 * (grada 0) y una de la materia de la asignatura (grada 1)—, y comprueba que el origen
 * copia **sólo la más específica**. Sin eso la prueba diría «200» y no habría mirado nada.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function pedirBoletinAparte(string $metodo, string $uri, array $cuerpo = [], ?string $token = null): array
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

function tokenBoletinAparte(string $usuario, string $clave): ?string
{
    foreach ([0, 5, 15, 40] as $espera) {
        if ($espera > 0) {
            sleep($espera);
        }

        $r = pedirBoletinAparte('POST', '/api/login/credentials', ['username' => $usuario, 'password' => $clave]);

        if (isset($r['cuerpo']['el_token'])) {
            return $r['cuerpo']['el_token'];
        }

        if ($r['estado'] !== 429) {
            echo '  login de '.$usuario.': '.$r['estado'].' '
                .mb_substr(json_encode($r['cuerpo']) ?: '', 0, 200).PHP_EOL;

            return null;
        }
    }

    return null;
}

function linea(string $texto): void
{
    echo PHP_EOL.'── '.$texto.' '.str_repeat('─', max(0, 72 - mb_strlen($texto))).PHP_EOL;
}

function mostrar(string $que, array $r): void
{
    echo '  '.$que.PHP_EOL;
    echo '    → '.$r['estado'].'  '.mb_substr(json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE) ?: '', 0, 700).PHP_EOL;
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL;

/*
 * **Se pregunta ANTES de intentar el login, y no es cortesía: un login fallido ESCRIBE.**
 * `Services\Login` anota el intento en `auditoria` y en `bitacoras`, y esas dos filas
 * sobreviven a la prueba porque no son basura suya sino el rastro que el colegio mira.
 * Contra una base de tests eso pone en rojo `AuditoriaDeLosDiezEscritoresTest`, que
 * cuenta exactamente una línea de `intento_login`. Aquí sólo se corre si existe.
 */
$hay = DB::selectOne('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL', ['administrador']);

if ($hay === null) {
    echo 'No hay `administrador` en esta base: esta herramienta es sólo para la de desarrollo.'.PHP_EOL;
    exit(1);
}

$jefe = tokenBoletinAparte('administrador', 'patreongreat');

if ($jefe === null) {
    echo 'Sin token no se puede seguir.'.PHP_EOL;
    exit(1);
}

/*
 * **El año NO es una columna de `users`.** `users` tiene `periodo_id` y el año sale del
 * periodo: lo mismo que hace `ContextoDeUsuario` para montar el `$this->user->year_id`
 * que leen los controladores. Preguntarle a `users` por `year_id` da un 1054 — medido.
 */
$yo = DB::selectOne(
    'SELECT u.id, u.periodo_id, p.year_id
       FROM users u INNER JOIN periodos p ON p.id = u.periodo_id
      WHERE u.username = ?',
    ['administrador']
);
$yearId = (int) $yo->year_id;
$periodoDelToken = (int) $yo->periodo_id;

echo 'year del token: '.$yearId.' · periodo del token: '.$periodoDelToken.PHP_EOL;

DB::beginTransaction();

$revertido = false;
$revertir = function () use (&$revertido) {
    if (! $revertido) {
        $revertido = true;
        DB::rollBack();
        echo PHP_EOL.'(transacción revertida: la base de desarrollo queda como estaba)'.PHP_EOL;
    }
};
register_shutdown_function($revertir);

try {
    /*
     * Un grupo del año del token con rejilla de curso en el periodo del token, y un
     * alumno suyo que **no** esté marcado. Se elige por consulta y no a mano: los ids
     * de `simonbolivar` cambian entre restauraciones.
     */
    $grupo = DB::selectOne(
        'SELECT g.id, g.nombre, COUNT(u.id) AS unidades
           FROM grupos g
           INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
           INNER JOIN unidades u ON u.asignatura_id = a.id AND u.periodo_id = ?
                                AND u.alumno_id IS NULL AND u.deleted_at IS NULL
          WHERE g.year_id = ? AND g.deleted_at IS NULL
          GROUP BY g.id
          ORDER BY COUNT(u.id) DESC
          LIMIT 1',
        [$periodoDelToken, $yearId]
    );

    if ($grupo === null) {
        echo 'No hay ningún grupo con rejilla de curso en el periodo del token.'.PHP_EOL;
        $revertir();
        exit(1);
    }

    $alumno = DB::selectOne(
        'SELECT m.alumno_id
           FROM matriculas m
          WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
            AND NOT EXISTS (SELECT 1 FROM bol_ind_periodos b
                             WHERE b.alumno_id = m.alumno_id AND b.periodo_id = ?)
          ORDER BY m.alumno_id LIMIT 1',
        [$grupo->id, $periodoDelToken]
    );

    $asignatura = DB::selectOne(
        'SELECT a.id, a.materia_id
           FROM asignaturas a
           INNER JOIN unidades u ON u.asignatura_id = a.id AND u.periodo_id = ?
                                AND u.alumno_id IS NULL AND u.deleted_at IS NULL
          WHERE a.grupo_id = ? AND a.deleted_at IS NULL
          GROUP BY a.id LIMIT 1',
        [$periodoDelToken, $grupo->id]
    );

    $alumnoId = (int) $alumno->alumno_id;
    $asignaturaId = (int) $asignatura->id;

    echo 'grupo '.$grupo->id.' ('.$grupo->nombre.', '.$grupo->unidades.' unidades de curso)'
        .' · alumno '.$alumnoId.' · asignatura '.$asignaturaId.PHP_EOL;

    // ─────────────────────────────────────────────────────────────────────────
    linea('1 · el tercer origen `plantilla` en POST boletin-independiente/copiar');

    // Primero hay que marcarlo: `copiarleA()` devuelve `no_marcado` para quien no lo está.
    pedirBoletinAparte('PUT', '/api/boletin-independiente/periodo',
        ['alumno_id' => $alumnoId, 'periodo_id' => $periodoDelToken, 'aplica' => true], $jefe);

    mostrar('origen {"tipo":"plantilla","periodo_id":'.$periodoDelToken.'}', pedirBoletinAparte(
        'POST', '/api/boletin-independiente/copiar',
        [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoDelToken,
            'alumnos_destino' => [$alumnoId],
            'origen' => ['tipo' => 'plantilla', 'periodo_id' => $periodoDelToken],
        ], $jefe
    ));

    mostrar('origen {"tipo":"plantilla"} — sin plantilla sembrada en la base', pedirBoletinAparte(
        'POST', '/api/boletin-independiente/copiar',
        [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoDelToken,
            'alumnos_destino' => [$alumnoId],
            'origen' => ['tipo' => 'plantilla'],
        ], $jefe
    ));

    /*
     * Y ahora con plantilla de verdad: **dos gradas** para que la precedencia tenga algo
     * que decidir. La general (grada 0) tiene DOS unidades y la de la materia (grada 1)
     * una sola: si el origen copiara las tres, la suma saldría 200 y se vería.
     */
    $ahora = date('Y-m-d H:i:s');

    $general = DB::table('unidades_por_defecto')->insertGetId([
        'definicion' => 'PRUEBA general', 'porcentaje' => 60, 'year_id' => $yearId,
        'obligatoria' => 0, 'orden' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    $general2 = DB::table('unidades_por_defecto')->insertGetId([
        'definicion' => 'PRUEBA general 2', 'porcentaje' => 40, 'year_id' => $yearId,
        'obligatoria' => 0, 'orden' => 2, 'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    $deLaMateria = DB::table('unidades_por_defecto')->insertGetId([
        'definicion' => 'PRUEBA de la materia', 'porcentaje' => 100, 'year_id' => $yearId,
        'materia_id' => $asignatura->materia_id,
        'obligatoria' => 0, 'orden' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    foreach ([$general => 2, $general2 => 1, $deLaMateria => 3] as $unidad => $cuantas) {
        for ($i = 1; $i <= $cuantas; $i++) {
            DB::table('subunidades_por_defecto')->insert([
                'definicion' => 'PRUEBA sub '.$i, 'porcentaje' => (int) (100 / $cuantas),
                'unidad_defec_id' => $unidad, 'nota_default' => 0, 'obligatoria' => 0,
                'orden' => $i, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    echo '  (sembradas 3 unidades de plantilla: 2 generales y 1 de la materia '
        .$asignatura->materia_id.')'.PHP_EOL;

    mostrar('origen {"tipo":"plantilla"} — CON plantilla', pedirBoletinAparte(
        'POST', '/api/boletin-independiente/copiar',
        [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoDelToken,
            'alumnos_destino' => [$alumnoId],
            'origen' => ['tipo' => 'plantilla'],
            'si_ya_tiene' => 'reemplazar',
        ], $jefe
    ));

    mostrar('origen {"tipo":"plantilla"} + con_notas', pedirBoletinAparte(
        'POST', '/api/boletin-independiente/copiar',
        [
            'asignatura_id' => $asignaturaId,
            'periodo_id' => $periodoDelToken,
            'alumnos_destino' => [$alumnoId],
            'origen' => ['tipo' => 'plantilla'],
            'con_notas' => true,
        ], $jefe
    ));

    $copiadas = DB::select(
        'SELECT definicion, porcentaje FROM unidades
          WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL
          ORDER BY orden, id',
        [$alumnoId, $asignaturaId, $periodoDelToken]
    );
    echo '  unidades propias del alumno tras copiar: '
        .json_encode($copiadas, JSON_UNESCAPED_UNICODE).PHP_EOL;

    // ─────────────────────────────────────────────────────────────────────────
    linea('2 · PUT boletin-independiente/periodo — ¿siembra al marcar?');

    $otro = DB::selectOne(
        'SELECT m.alumno_id
           FROM matriculas m
          WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
            AND m.alumno_id <> ?
            AND NOT EXISTS (SELECT 1 FROM bol_ind_periodos b
                             WHERE b.alumno_id = m.alumno_id AND b.periodo_id = ?)
          ORDER BY m.alumno_id LIMIT 1',
        [$grupo->id, $alumnoId, $periodoDelToken]
    );

    $otroId = (int) $otro->alumno_id;

    $cuenta = function (int $id) use ($periodoDelToken): array {
        $u = DB::selectOne(
            'SELECT COUNT(*) c FROM unidades WHERE alumno_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$id, $periodoDelToken]
        )->c;
        $s = DB::selectOne(
            'SELECT COUNT(*) c FROM subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.alumno_id = ? AND u.periodo_id = ?
                                    AND u.deleted_at IS NULL
              WHERE s.deleted_at IS NULL',
            [$id, $periodoDelToken]
        )->c;
        $n = DB::selectOne(
            'SELECT COUNT(*) c FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.alumno_id = ? AND u.periodo_id = ?
                                    AND u.deleted_at IS NULL
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL',
            [$id, $periodoDelToken, $id]
        )->c;

        return ['unidades_propias' => (int) $u, 'subunidades_propias' => (int) $s, 'notas_propias' => (int) $n];
    };

    echo '  alumno '.$otroId.' ANTES de marcar: '.json_encode($cuenta($otroId)).PHP_EOL;

    mostrar('PUT periodo {aplica:true}', pedirBoletinAparte(
        'PUT', '/api/boletin-independiente/periodo',
        ['alumno_id' => $otroId, 'periodo_id' => $periodoDelToken, 'aplica' => true], $jefe
    ));

    echo '  alumno '.$otroId.' DESPUÉS de marcar: '.json_encode($cuenta($otroId)).PHP_EOL;

    mostrar('PUT periodo {aplica:true} otra vez — no puede duplicar', pedirBoletinAparte(
        'PUT', '/api/boletin-independiente/periodo',
        ['alumno_id' => $otroId, 'periodo_id' => $periodoDelToken, 'aplica' => true], $jefe
    ));

    echo '  alumno '.$otroId.' tras marcar DOS veces: '.json_encode($cuenta($otroId)).PHP_EOL;

    mostrar('PUT periodo {aplica:false} — el camino viejo, que sí sembraba', pedirBoletinAparte(
        'PUT', '/api/boletin-independiente/periodo',
        ['alumno_id' => $otroId, 'periodo_id' => $periodoDelToken, 'aplica' => false], $jefe
    ));

    // ─────────────────────────────────────────────────────────────────────────
    linea('3 · PUT boletin-independiente/planilla — ¿qué manda de la plantilla?');

    $planilla = pedirBoletinAparte('PUT', '/api/boletin-independiente/planilla', ['asignatura_id' => $asignaturaId], $jefe);
    echo '  estado: '.$planilla['estado'].PHP_EOL;
    echo '  claves de la respuesta: '.json_encode(array_keys($planilla['cuerpo'] ?? [])).PHP_EOL;
    echo '  claves de `asignatura`: '.json_encode(array_keys($planilla['cuerpo']['asignatura'] ?? [])).PHP_EOL;

    if (isset($planilla['cuerpo']['plantilla_del_colegio'])) {
        echo '  plantilla_del_colegio: '
            .json_encode($planilla['cuerpo']['plantilla_del_colegio'], JSON_UNESCAPED_UNICODE).PHP_EOL;
    } else {
        echo '  plantilla_del_colegio: NO VIENE'.PHP_EOL;
    }

    // ─────────────────────────────────────────────────────────────────────────
    linea('4 · PUT boletin-independiente/marcados — la lista del menú');

    $marcados = pedirBoletinAparte('PUT', '/api/boletin-independiente/marcados', ['periodo_id' => $periodoDelToken], $jefe);
    echo '  estado: '.$marcados['estado'].PHP_EOL;
    echo '  claves: '.json_encode(array_keys($marcados['cuerpo'] ?? [])).PHP_EOL;
} finally {
    $revertir();
}
