<?php

/**
 * La comprobación propia del módulo de horario, para el día del despliegue.
 *
 * Contesta **la única pregunta que distingue las dos cosas que se parecen**:
 *
 *     «el módulo está y este colegio no ha subido nada»   ->  200 con total: 0
 *     «el módulo no llegó a este colegio»                 ->  404
 *     «llegó el código y no la migración»                 ->  500
 *
 * Las tres se ven igual desde la pantalla —una rejilla vacía— y por eso hace
 * falta preguntárselo a la API. `migrate:status` no sirve aquí: dice que la
 * migración corrió, no que la ruta conteste; y la comprobación de esquema de
 * `docs/DESPLIEGUE.md` tampoco, porque pregunta por columnas y tablas, no por
 * lo que devuelve el router con un token delante.
 *
 * Uso (dentro del contenedor, o en el colegio después de `migrate --force`):
 *
 *     php tools/comprobar-el-horario.php
 *     DB_DATABASE=otrocolegio php tools/comprobar-el-horario.php
 *     php tools/comprobar-el-horario.php --control     # sin base y sin árbol
 *
 * Tres códigos de salida: `0` el módulo contesta como debe, `1` contesta algo
 * que no es, `2` **NO MEDIDO** — no se pudo llegar a preguntar. El 2 es el que
 * importa en el bucle de los diecisiete: un colegio sin personal activo, o cuya
 * base no contesta, **no es un colegio limpio**.
 *
 * ## Lo que ESCRIBE, porque escribe
 *
 * Para preguntar con token hace falta un token, así que abre una sesión de
 * verdad para el primer usuario de personal activo y **la cierra al terminar**.
 * El borrado va por el nombre de la sesión —`web:<uuid>`, el que comparten el
 * token de acceso y el de refresco—, sacado del propio token que acaba de
 * emitir, y **no por el usuario**: un `DELETE ... WHERE tokenable_id = ?` en un
 * colegio vivo echaría de la aplicación a esa persona en mitad de su jornada, y
 * el síntoma sería «se me cerró la sesión sola» el día del despliegue, que es
 * justo el día en que nadie lo atribuiría a esto.
 *
 * ## El control del alumno no es adorno
 *
 * La ruta lleva `auth.personal`, y lo que reparte —qué docente está dónde a cada
 * hora— es la razón. Un `200` para el personal no dice nada sobre si la puerta
 * está cerrada: eso lo dice el `403` del alumno, y por eso las dos preguntas
 * viajan juntas. Si el colegio no tiene alumnos activos, esa mitad sale **sin
 * medir** y se dice, en vez de darla por buena.
 */

/**
 * El control de la herramienta: que el veredicto diga lo que su nombre promete.
 *
 * Corre sin base y sin Laravel. Existe porque este fichero devuelve un código de
 * salida que va a leer un bucle, y un veredicto que se equivoca en silencio es
 * peor que no tenerlo: **el primer sitio donde mirar cuando el número sale raro
 * es el detector**.
 */
function controlDeLaComprobacion(): int
{
    $casos = [
        // [estado, total, control del alumno, veredicto esperado, código]
        ['recién migrado, sin horarios', 200, 0, 403, 'LLEGO', 0],
        ['ya tiene versiones', 200, 6, 403, 'LLEGO', 0],
        ['el módulo no llegó', 404, null, 404, 'FALLA', 1],
        ['llegó el código y no la migración', 500, null, 500, 'FALLA', 1],
        ['la puerta abierta al alumno', 200, 0, 200, 'FALLA', 1],
        ['200 sin la clave total', 200, null, 403, 'FALLA', 1],
        ['total que no es un número', 200, 'cero', 403, 'FALLA', 1],
    ];

    foreach ($casos as [$nombre, $estado, $total, $alumno, $esperado, $codigo]) {
        [$veredicto, $salida] = veredicto($estado, $total, $alumno);
        if (! str_starts_with($veredicto, $esperado) || $salida !== $codigo) {
            fwrite(STDERR, "FALLA el control «{$nombre}»: dio [{$veredicto}] con código {$salida}\n");

            return 1;
        }
    }

    echo 'OK — los '.count($casos)." casos del veredicto se deciden como está escrito.\n";

    return 0;
}

/**
 * El veredicto y su código de salida, apartados para que el control los pueda
 * ejercitar sin base de datos.
 *
 * `$total` llega como `null` cuando la respuesta no traía la clave, que **no es
 * lo mismo que cero**: un `total` ausente es una respuesta de otra forma —o de
 * otra versión del código— y darlo por cero sería exactamente el `[]` que este
 * módulo tiene escrito que no quiere.
 *
 * @return array{0: string, 1: int}
 */
function veredicto(int $estado, mixed $total, ?int $controlAlumno): array
{
    $puertaCerrada = $controlAlumno === 403 || $controlAlumno === null;

    if ($estado === 200 && is_int($total) && $puertaCerrada) {
        return [
            $total === 0
                ? 'LLEGO - el módulo está y este colegio no ha subido nada'
                : "LLEGO - el módulo está y este colegio ya tiene {$total} versión(es)",
            0,
        ];
    }

    $porque = match (true) {
        $estado === 404 => 'la ruta no existe: el código del horario NO llegó a este colegio',
        $estado === 500 => 'la ruta revienta: mira si `migrate --force` corrió entero',
        $estado === 403 => 'el usuario que preguntó no es personal, o el guard no es el que era',
        ! is_int($total) => 'contesta 200 pero sin un `total` numérico: no es la respuesta de esta ruta',
        default => 'el alumno NO recibió 403: la puerta de `auth.personal` no está cerrada',
    };

    return ["FALLA - {$porque}", 1];
}

if (in_array('--control', $argv ?? [], true)) {
    exit(controlDeLaComprobacion());
}

require __DIR__.'/../vendor/autoload.php';

use App\Models\TokenDeSesion;
use App\Services\Sesion;
use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as KernelHttp;
use Illuminate\Http\Request;

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Una excepción aquí no puede salir con 0: el bootstrap de Laravel la pinta muy
// bien y devuelve cero, y en el bucle de los diecisiete ese colegio se contaría
// como mirado. Sale 2 porque no es un hallazgo: es que no se pudo mirar.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n!! NO MEDIDO — no se pudo preguntar\n\n   ".$e->getMessage()."\n\n"
        ."   Esto NO es «este colegio tiene el horario bien»: es que no se ha preguntado\n"
        ."   nada. Comprueba a qué base apunta `DB_DATABASE`.\n");
    exit(2);
});

/** El primer usuario vivo y activo de los tipos que se le pidan. */
$primerUsuarioVivo = static fn (array $tipos): ?User => User::query()
    ->whereIn('tipo', $tipos)
    ->whereNull('deleted_at')
    ->where('is_active', 1)
    ->orderBy('id')
    ->first();

/**
 * Pega a la ruta con un token recién emitido para ese usuario, y cierra la
 * sesión que abrió — sólo ésa.
 *
 * @return array{0: int, 1: array<string, mixed>|null}
 */
$pegarConToken = static function (User $usuario) use ($app): array {
    $sesion = $app->make(Sesion::class)->abrir($usuario, 'web');

    $peticion = Request::create('/api/horario/versiones', 'GET');
    $peticion->headers->set('Authorization', 'Bearer '.$sesion['el_token']);
    $peticion->headers->set('Accept', 'application/json');

    $respuesta = $app->make(KernelHttp::class)->handle($peticion);

    // El token plano de Sanctum es `<id>|<secreto>`: del id sale la fila, y de
    // la fila el nombre de la sesión, que es lo que comparten el token de
    // acceso y el de refresco. Borrar por ahí cierra ESTA sesión y ninguna más.
    $id = (int) strtok((string) $sesion['el_token'], '|');
    $fila = TokenDeSesion::query()->find($id);
    if ($fila !== null) {
        TokenDeSesion::query()
            ->where('tokenable_id', $usuario->id)
            ->where('name', $fila->name)
            ->delete();
    }

    $cuerpo = json_decode((string) $respuesta->getContent(), true);

    return [$respuesta->getStatusCode(), is_array($cuerpo) ? $cuerpo : null];
};

$personal = $primerUsuarioVivo(['Profesor', 'Usuario']);
$alumno = $primerUsuarioVivo(['Alumno']);
$base = (string) config('database.connections.mysql.database');

echo "\nCOMPROBACIÓN DEL MÓDULO DE HORARIO — `GET horario/versiones`\n";
echo "base `{$base}`\n";
echo str_repeat('─', 78)."\n\n";

if ($personal === null) {
    fwrite(STDERR, "!! NO MEDIDO — este colegio no tiene ni un usuario de personal activo,\n"
        ."   así que no hay con qué preguntar. No es «está bien»: es que no se ha mirado.\n");
    exit(2);
}

echo "0. CON QUIÉN SE PREGUNTA\n";
printf("   Personal                            %s (%s, id %d)\n", $personal->username, $personal->tipo, $personal->id);
// Ternario explícito y no `$alumno?->username ?? '…'`: larastan infiere que
// `first()` no devuelve null y marca el nullsafe como inútil, pero **sí puede
// ser null** —un colegio sin alumnos activos existe— y de ahí sale el «SIN
// MEDIR» de abajo. La forma que el analizador acepta es la que además se lee.
printf("   Alumno para el control              %s\n",
    $alumno === null ? 'NINGUNO ACTIVO — esa mitad queda sin medir' : $alumno->username);

[$estado, $cuerpo] = $pegarConToken($personal);
$controlAlumno = $alumno === null ? null : $pegarConToken($alumno)[0];

// `array_key_exists` y no `??`: la clave `oficial_id` **vale `null` a propósito**
// cuando el año no ha publicado ninguna versión, que es el estado normal de un
// colegio recién migrado. Con `??` ese null se imprimiría como «no viene la
// clave», o sea que la respuesta correcta se leería como una respuesta rota.
$total = is_array($cuerpo) && array_key_exists('total', $cuerpo) ? $cuerpo['total'] : null;
$hayOficial = is_array($cuerpo) && array_key_exists('oficial_id', $cuerpo);

echo "\n1. LO QUE CONTESTA\n";
printf("   GET horario/versiones               %d\n", $estado);
printf("   total                               %s\n", $total === null ? 'SIN LA CLAVE `total`' : json_encode($total));
printf("   oficial_id                          %s\n", $hayOficial ? json_encode($cuerpo['oficial_id']) : 'SIN LA CLAVE `oficial_id`');
printf("   year_id del token                   %s\n", is_array($cuerpo) && array_key_exists('year_id', $cuerpo) ? json_encode($cuerpo['year_id']) : '—');

echo "\n2. EL CONTROL: la misma ruta con token de ALUMNO\n";
printf("   Tiene que dar 403                   %s\n", $controlAlumno === null ? 'SIN MEDIR — no hay alumnos activos' : (string) $controlAlumno);

[$texto, $salida] = veredicto($estado, $total, $controlAlumno);

echo "\n".str_repeat('─', 78)."\n";
echo $texto."\n";
if ($controlAlumno === null && $salida === 0) {
    echo "  (y la puerta del guard NO se ha comprobado: este colegio no tiene alumnos activos)\n";
}

exit($salida);
