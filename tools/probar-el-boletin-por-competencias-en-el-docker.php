<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Le pide de verdad **las dos rutas de la Fase 6** a la aplicación, contra la base de
 * DESARROLLO, con el token real de `administrador` y **también con el de un alumno**,
 * que es el único que ejerce `boletin.propio`.
 *
 * Existe por lo mismo que sus hermanas de `competencias` y `desempenos`: leer el PHP no
 * basta, y el servidor web del contenedor sirve `/app` —el árbol principal—, así que
 * desde un worktree no hay puerto por el que llamar. Esto levanta el kernel HTTP **del
 * worktree** y le mete peticiones por la misma puerta que nginx.
 *
 *   docker exec -w /app/.worktrees/f6 8myvc-app-1 php tools/probar-el-boletin-por-competencias-en-el-docker.php
 *
 * > **NO se corre mientras haya una tanda de tests contra esa base.** Esto ESCRIBE —crea
 * > un usuario, entra y lo borra—, así que durante unos segundos hay una fila de más en
 * > `users`, y cualquier barrido de superficie que pase por ahí la ve. Costó dos tandas
 * > enteras la noche del 13 sep 2026 (03-tests.md, «Una herramienta de `tools/` contra la
 * > base de TESTS deja rastro»). El paso 1 de aquella sección —`pgrep -af phpunit`— vale
 * > también **en la otra dirección**.
 *
 * ## Lo que escribe, y por qué lo deshace
 *
 * En `simonbolivar` **no hay ni un grupo con `caritas = 1`** —la columna nació con
 * defecto 0 y nadie la ha encendido— ni una sola celda de rejilla, así que sin escribir
 * nada esta prueba comprobaría la mitad de D17 y ninguna de la Fase 4. Así que:
 *
 *   - enciende `caritas` en un grupo y **lo devuelve a su valor de antes**;
 *   - escribe unas pocas celdas en `frases_asignatura` y **las borra físicamente** por
 *     el id que le devolvió el `insert`, nunca por un `WHERE` ancho;
 *   - crea una competencia y un desempeño propios, y los borra igual.
 *
 * Todo dentro de una transacción que **se revierte siempre**, incluso si algo revienta:
 * la base de desarrollo la comparten varias sesiones, y esto es un informe, no una
 * migración.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

/**
 * Como `pedir()` de los otros cinco scripts, **más el tiempo y el número de
 * consultas** — que es para lo que existe este fichero.
 *
 * **Se llama distinto a propósito, y eso arregla un rojo de larastan** (14 sep
 * 2026). Siete scripts de `tools/` declaraban `function pedir()` en el espacio
 * global: cinco devolviendo `{estado, cuerpo}` y dos —éste y su hermano—
 * devolviendo cuatro claves. PHPStan resuelve un nombre global a **una sola**
 * declaración, se quedaba con la de dos claves y marcaba `ms` y `consultas` como
 * offsets inexistentes en los dos que sí las devuelven: **8 errores, y el código
 * era correcto**.
 *
 * Anotar el `@return` no lo arreglaba —el que gana es el que phpstan resolvió, no
 * el que se anota—, así que lo que se separa es el nombre. Y de paso deja de ser
 * un accidente: **medir y no medir son dos funciones**, y ahora se llaman distinto.
 *
 * `tools/` no lo mira ninguna suite; larastan es lo único que pasa por esta
 * carpeta, así que un rojo aquí sólo lo ve quien corra `composer run stan`.
 *
 * @return array{estado: int, cuerpo: mixed, ms: float, consultas: int}
 */
function pedirMidiendo(string $metodo, string $uri, array $cuerpo = [], ?string $token = null): array
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

    DB::flushQueryLog();
    DB::enableQueryLog();
    $t0 = microtime(true);
    $respuesta = $kernel->handle($peticion);
    $ms = (microtime(true) - $t0) * 1000;
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [
        'estado' => $respuesta->getStatusCode(),
        'cuerpo' => json_decode((string) $respuesta->getContent(), true),
        'ms' => $ms,
        'consultas' => $consultas,
    ];
}

function token(string $usuario, string $clave): ?string
{
    foreach ([0, 5, 15, 40] as $espera) {
        if ($espera > 0) {
            sleep($espera);
        }

        $r = pedirMidiendo('POST', '/api/login/credentials', ['username' => $usuario, 'password' => $clave]);

        if (isset($r['cuerpo']['el_token'])) {
            return $r['cuerpo']['el_token'];
        }

        if ($r['estado'] !== 429) {
            echo '  login de '.$usuario.': '.$r['estado'].' '
                .mb_substr(json_encode($r['cuerpo']) ?: '', 0, 160).PHP_EOL;

            return null;
        }
    }

    return null;
}

echo 'árbol: '.realpath(__DIR__.'/..').PHP_EOL;
echo 'base:  '.config('database.connections.'.config('database.default').'.database').PHP_EOL.PHP_EOL;

/*
 * **El usuario, y por qué hay un plan B.**
 *
 * Contra la base de desarrollo el usuario es `administrador`, que es el que pide el
 * encargo. Pero la Fase 4 —de quien esto lee sus tres columnas— **todavía no está
 * migrada en `simonbolivar`**, así que mientras eso siga así esta prueba se corre
 * contra la base de tests de la sesión:
 *
 *   docker exec -w /app/.worktrees/f6 -e DB_DATABASE=simonbolivar_testing_f6 \
 *       8myvc-app-1 php tools/probar-el-boletin-por-competencias-en-el-docker.php
 *
 * Y ahí **no existe `administrador`**: el seed está anonimizado. Así que si no está, se
 * crea un superusuario de paso y **se borra físicamente al final**, que es lo que ya hace
 * `probar-desempenos-en-el-docker.php` con su docente llano. Lo que NO se hace es migrar
 * la base compartida por mi cuenta: la migración es de otra fase y de otra sesión.
 */
const USUARIO_DE_PASO = 'jefe-de-prueba-boletin-competencias';
const CLAVE_DE_PASO = 'prueba-boletin-competencias-1234';

DB::table('users')->where('username', USUARIO_DE_PASO)->delete();

/*
 * **Se pregunta ANTES de intentar el login, y eso no es cortesía: un login fallido
 * ESCRIBE.** `Services\Login` anota el intento en `auditoria` y en `bitacoras` —una fila
 * cada uno, con `actor_intentado`— y esas dos filas **sobreviven a la prueba**, porque no
 * son basura de la prueba sino el rastro que el colegio mira cuando alguien reclama.
 *
 * Contra la base de tests eso rompe una prueba ajena:
 * `AuditoriaDeLosDiezEscritoresTest::un_login_fallido_no_inventa_un_actor` cuenta
 * **exactamente una** línea de `intento_login`, y el intento de esta herramienta le añadía
 * la segunda. Medido: la suite entera salió con ese único rojo, en una clase que no toca
 * nada de esta fase. **Una herramienta que deja rastro en la base de tests pone en rojo un
 * test de otro**, y el rojo aparece lejísimos de la causa.
 */
$hayAdministrador = DB::selectOne('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL',
    ['administrador']) !== null;

$jefe = $hayAdministrador ? token('administrador', 'patreongreat') : null;
$usuarioDePaso = null;

if ($jefe === null) {
    echo '  (no hay `administrador` en esta base: se crea un superusuario de paso)'.PHP_EOL;

    $unPeriodo = DB::selectOne('SELECT p.id FROM periodos p
                                 INNER JOIN years y ON y.id = p.year_id AND y.actual = 1
                                 WHERE p.deleted_at IS NULL ORDER BY p.numero, p.id LIMIT 1');

    $usuarioDePaso = DB::table('users')->insertGetId([
        'username' => USUARIO_DE_PASO,
        'password' => Hash::make(CLAVE_DE_PASO),
        'tipo' => 'Usuario',
        'is_active' => 1,
        'is_superuser' => 1,
        'periodo_id' => $unPeriodo->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $jefe = token(USUARIO_DE_PASO, CLAVE_DE_PASO);
}

if ($jefe === null) {
    echo 'Sin token no se puede seguir.'.PHP_EOL;
    exit(1);
}

register_shutdown_function(function () use ($usuarioDePaso) {
    if ($usuarioDePaso === null) {
        return;
    }

    DB::table('users')->where('id', $usuarioDePaso)->delete();
    DB::table('personal_access_tokens')->where('tokenable_id', $usuarioDePaso)->delete();

    // Y su rastro, **acotado por el nombre que esta herramienta se inventó**, nunca por
    // fecha ni por tabla entera: el rastro de los demás es lo que el colegio mira cuando
    // alguien reclama. Lo que se borra es una fila que apunta a un usuario que ya no
    // existe porque lo creó esta prueba hace diez segundos.
    DB::table('auditoria')->where('actor_nombre', USUARIO_DE_PASO)
        ->orWhere('actor_intentado', USUARIO_DE_PASO)->delete();
    DB::table('bitacoras')->where('affected_person_name', USUARIO_DE_PASO)->delete();

    echo '  (el superusuario de paso y su rastro quedan borrados)'.PHP_EOL;
});

$yo = DB::selectOne('SELECT periodo_id FROM users WHERE username = ?',
    [$usuarioDePaso === null ? 'administrador' : USUARIO_DE_PASO]);
$periodo = DB::selectOne('SELECT id, numero, year_id FROM periodos WHERE id = ?', [$yo->periodo_id]);

$asignatura = DB::selectOne(
    'SELECT a.id, a.grupo_id, a.materia_id, g.caritas, g.grado_id, g.year_id
       FROM asignaturas a
       INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
      WHERE a.deleted_at IS NULL AND a.profesor_id IS NOT NULL AND g.year_id = ?
      ORDER BY a.id LIMIT 1',
    [$periodo->year_id]
);

$alumno = DB::selectOne(
    'SELECT m.alumno_id FROM matriculas m
      WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
      ORDER BY m.alumno_id LIMIT 1',
    [$asignatura->grupo_id]
);

echo 'periodo '.$periodo->id.' (nº '.$periodo->numero.', año '.$periodo->year_id.')'.PHP_EOL;
echo 'grupo '.$asignatura->grupo_id.' · asignatura '.$asignatura->id.' · alumno '.$alumno->alumno_id.PHP_EOL;
echo 'caritas del grupo, tal como está: '.$asignatura->caritas.PHP_EOL.PHP_EOL;

$ruta = '/api/boletines-competencias/detailed-notas/'.$asignatura->grupo_id;
$cuerpo = ['requested_alumnos' => [['alumno_id' => $alumno->alumno_id]]];

echo '── tal como está la base, sin tocar nada ───────────────────────────'.PHP_EOL;
$r = pedirMidiendo('PUT', $ruta, $cuerpo, $jefe);
printf("  PUT detailed-notas            %d   %6.0f ms   %4d consultas   %7d bytes\n",
    $r['estado'], $r['ms'], $r['consultas'], strlen(json_encode($r['cuerpo']) ?: ''));
echo '  posiciones de la respuesta:   '.count($r['cuerpo'] ?? []).PHP_EOL;
echo '  poblacion: '.json_encode($r['cuerpo'][4] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;

$g = pedirMidiendo('PUT', '/api/boletines-competencias/detailed-notas-group/'.$asignatura->grupo_id, [], $jefe);
printf("  PUT detailed-notas-group      %d   %6.0f ms   %4d consultas   %7d bytes\n",
    $g['estado'], $g['ms'], $g['consultas'], strlen(json_encode($g['cuerpo']) ?: ''));
echo '  poblacion: '.json_encode($g['cuerpo'][4] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL.PHP_EOL;

echo '── los errores, pedidos uno a uno ──────────────────────────────────'.PHP_EOL;
$sinToken = pedirMidiendo('PUT', $ruta, $cuerpo);
echo '  sin token                      '.$sinToken['estado'].PHP_EOL;

$grupoQueNoExiste = pedirMidiendo('PUT', '/api/boletines-competencias/detailed-notas/999999', [], $jefe);
echo '  grupo que no existe            '.$grupoQueNoExiste['estado'].'   '
    .mb_substr(json_encode($grupoQueNoExiste['cuerpo']) ?: '', 0, 90).PHP_EOL;

$grupoBorrado = DB::selectOne('SELECT id FROM grupos WHERE deleted_at IS NOT NULL ORDER BY id LIMIT 1');

if ($grupoBorrado !== null) {
    $r2 = pedirMidiendo('PUT', '/api/boletines-competencias/detailed-notas/'.$grupoBorrado->id, [], $jefe);
    echo '  grupo en la papelera ('.$grupoBorrado->id.')      '.$r2['estado'].PHP_EOL;
}

// El alumno de otro: es lo único que ejerce `boletin.propio`, y hace falta un token de
// Alumno. Se busca uno del seed con contraseña conocida; si no lo hay, se dice.
$otro = DB::selectOne(
    'SELECT u.username FROM users u
       INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
      WHERE u.tipo = "Alumno" AND u.is_active = 1 AND u.deleted_at IS NULL
      ORDER BY u.id LIMIT 1'
);
echo '  (un Alumno del seed: '.($otro->username ?? 'ninguno').' — sin su contraseña no se puede pedir 403 aquí;'.PHP_EOL;
echo '   lo cubre AutorizacionTest, que sí las tiene)'.PHP_EOL.PHP_EOL;

// ── Lo que hace falta escribir para ver D17 y la Fase 4 ─────────────────────
echo '── con `caritas` encendida y tres celdas puestas (y se deshace) ─────'.PHP_EOL;

DB::beginTransaction();

try {
    $escala = DB::selectOne(
        'SELECT id, desempenio FROM escalas_de_valoracion
          WHERE year_id = ? AND deleted_at IS NULL ORDER BY orden DESC, id DESC LIMIT 1',
        [$periodo->year_id]
    );

    DB::table('escalas_de_valoracion')->where('id', $escala->id)->update([
        'icono_infantil' => 'carita-feliz.png',
        'icono_adolescente' => 'estrella.png',
    ]);

    DB::table('grupos')->where('id', $asignatura->grupo_id)->update(['caritas' => 1]);

    $competenciaId = DB::table('competencias')->insertGetId([
        'year_id' => $periodo->year_id,
        'materia_id' => $asignatura->materia_id,
        'grado_id' => $asignatura->grado_id,
        'definicion' => 'Resuelve problemas con números racionales en contextos cotidianos',
        'orden' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $conCompetencia = DB::table('desempenos')->insertGetId([
        'asignatura_id' => $asignatura->id, 'periodo_id' => $periodo->id,
        'competencia_id' => $competenciaId,
        'definicion' => 'Identifica fracciones equivalentes y las ordena',
        'orden' => 0, 'por_defecto' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $suelto = DB::table('desempenos')->insertGetId([
        'asignatura_id' => $asignatura->id, 'periodo_id' => $periodo->id,
        'competencia_id' => null,
        'definicion' => 'Entrega sus trabajos a tiempo',
        'orden' => 1, 'por_defecto' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$conCompetencia, $suelto] as $desempenoId) {
        DB::table('frases_asignatura')->insert([
            'alumno_id' => $alumno->alumno_id,
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'frase' => DB::table('desempenos')->where('id', $desempenoId)->value('definicion'),
            'desempeno_id' => $desempenoId,
            'escala_id' => $escala->id,
            'nivel' => $escala->desempenio,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Una frase a mano, de las de siempre: sin casilla y sin nivel.
    DB::table('frases_asignatura')->insert([
        'alumno_id' => $alumno->alumno_id,
        'asignatura_id' => $asignatura->id,
        'periodo_id' => $periodo->id,
        'frase' => 'Felicitaciones por su desempeño en el periodo.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = pedirMidiendo('PUT', $ruta, $cuerpo, $jefe);

    printf("  PUT detailed-notas            %d   %6.0f ms   %4d consultas\n",
        $r['estado'], $r['ms'], $r['consultas']);
    echo '  grupo.caritas: '.json_encode($r['cuerpo'][0]['caritas'] ?? null).PHP_EOL;
    echo '  poblacion: '.json_encode($r['cuerpo'][4] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;

    foreach ($r['cuerpo'][2][0]['asignaturas'] ?? [] as $a) {
        if ((int) $a['asignatura_id'] !== (int) $asignatura->id) {
            continue;
        }

        echo PHP_EOL.'  '.$a['materia'].'   nota='.json_encode($a['nota_asignatura'])
            .'  nivel='.json_encode($a['desempenio'])
            .'  motivo='.json_encode($a['motivo_del_nivel'])
            .'  IH='.json_encode($a['creditos'])
            .'  F='.$a['total_ausencias'].PHP_EOL;

        foreach ($a['competencias'] as $c) {
            echo '    ['.$c['competencia_id'].'] '.$c['definicion'].PHP_EOL;

            foreach ($c['desempenos'] as $d) {
                echo '        · '.$d['texto'].'   ['.json_encode($d['nivel']).']'
                    .'  icono='.json_encode($d['icono_infantil']).PHP_EOL;
            }
        }

        echo '    sueltos:'.PHP_EOL;

        foreach ($a['desempenos_sueltos'] as $d) {
            echo '        · '.$d['texto'].'   ['.json_encode($d['nivel']).']'
                .'  origen='.$d['origen'].PHP_EOL;
        }
    }
} finally {
    DB::rollBack();
    echo PHP_EOL.'  (revertido: `caritas` vuelve a '.$asignatura->caritas
        .' y no queda ni una fila escrita)'.PHP_EOL;
}
