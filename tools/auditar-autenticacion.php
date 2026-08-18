<?php

/**
 * ¿Qué rutas de la API resuelven al usuario antes de trabajar, y cuáles no?
 *
 * El proyecto no tiene middleware de autenticación: cada método se defiende
 * solo, llamando a `User::fromToken()`. Ese método aborta con 401 si no hay
 * token, si expiró o si es inválido (app/User.php:85-99), así que llamarlo ES
 * una comprobación de autenticación — floja, pero lo es.
 *
 * Esto recorre las rutas reales del router y, para cada una, mira el cuerpo del
 * método con el analizador sintáctico (no con grep: un `fromToken` dentro de un
 * comentario o de una cadena no protege nada).
 *
 * Uso:
 *   docker exec 8myvc-app-1 php tools/auditar-autenticacion.php [--csv]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

// PhpParser 5: create() ya no existe; se pide el analizador de la versión que corre.
$parser = (new ParserFactory)->createForHostVersion();
$finder = new NodeFinder;

/** Cache: fichero → [ 'metodos' => [nombre => nodo], 'constructorAutentica' => bool ] */
$analizados = [];

/**
 * ¿Este trozo de árbol contiene una comprobación de identidad?
 *
 * Se buscan las cuatro formas que usa el proyecto. Cualquiera de ellas implica
 * que sin token válido no se sigue adelante.
 */
function autenticaEn(NodeFinder $finder, $nodos): array
{
    $senales = [];

    // User::fromToken(...) — la forma normal en este código.
    foreach ($finder->findInstanceOf($nodos, Node\Expr\StaticCall::class) as $llamada) {
        if (! $llamada->class instanceof Node\Name) {
            continue;
        }

        $clase  = strtolower($llamada->class->toString());
        $metodo = $llamada->name instanceof Node\Identifier ? strtolower($llamada->name->toString()) : '';

        if (str_ends_with($clase, 'user') && $metodo === 'fromtoken') {
            $senales['User::fromToken'] = true;
        }

        // JWTAuth::parseToken() / ::authenticate() / ::toUser()
        if (str_contains($clase, 'jwtauth') && in_array($metodo, ['parsetoken', 'authenticate', 'touser'], true)) {
            $senales['JWTAuth'] = true;
        }

        // Auth::check() / ::user() / ::id()
        if ($clase === 'auth' || str_ends_with($clase, '\\auth')) {
            $senales['Auth::' . $metodo] = true;
        }
    }

    // $this->user — el usuario ya resuelto en el constructor.
    foreach ($finder->findInstanceOf($nodos, Node\Expr\PropertyFetch::class) as $prop) {
        if ($prop->var instanceof Node\Expr\Variable
            && $prop->var->name === 'this'
            && $prop->name instanceof Node\Identifier
            && in_array($prop->name->toString(), ['user', 'usuario'], true)) {
            $senales['$this->user'] = true;
        }
    }

    // auth()->...  — el helper.
    foreach ($finder->findInstanceOf($nodos, Node\Expr\FuncCall::class) as $fn) {
        if ($fn->name instanceof Node\Name && strtolower($fn->name->toString()) === 'auth') {
            $senales['auth()'] = true;
        }
    }

    return array_keys($senales);
}

/**
 * Señales de autenticación del método, siguiendo lo que llama.
 *
 * No basta con mirar el cuerpo: el PR de seguridad metió guardas en métodos
 * auxiliares —`$this->exigirAdminUsuarios()` llama a `User::fromToken()` y
 * además exige el permiso— y mirando solo el cuerpo directo salían como
 * desprotegidos. Se siguen las llamadas a `$this->loQueSea()` dentro de la
 * misma clase, con un conjunto de visitados para no colgarse en recursión.
 */
function senalesTransitivas(NodeFinder $finder, array $ctrl, string $metodo, array $vistos = []): array
{
    if (isset($vistos[$metodo]) || ! isset($ctrl['metodos'][$metodo])) {
        return [];
    }

    $vistos[$metodo] = true;
    $nodo = $ctrl['metodos'][$metodo];

    $senales = autenticaEn($finder, [$nodo]);

    foreach ($finder->findInstanceOf([$nodo], Node\Expr\MethodCall::class) as $llamada) {
        if (! $llamada->var instanceof Node\Expr\Variable
            || $llamada->var->name !== 'this'
            || ! $llamada->name instanceof Node\Identifier) {
            continue;
        }

        $auxiliar = $llamada->name->toString();

        foreach (senalesTransitivas($finder, $ctrl, $auxiliar, $vistos) as $senal) {
            $senales[] = $senal . ' (vía $this->' . $auxiliar . '())';
        }
    }

    return array_values(array_unique($senales));
}

/**
 * ¿El método hace algo? Un cuerpo vacío no es un agujero: es un endpoint muerto.
 *
 * PermissionsController tiene cuatro así. Aparecían como desprotegidos y no lo
 * están: no hacen nada. Mezclarlos con los de verdad solo estorba a quien tiene
 * que revisar la lista.
 */
function estaVacio(Node\Stmt\ClassMethod $metodo): bool
{
    if ($metodo->stmts === null || $metodo->stmts === []) {
        return true;
    }

    // Un cuerpo que solo tiene un comentario ('//') no llega vacío: el
    // analizador mete un nodo Nop para no perder el comentario. Sigue siendo
    // un método que no hace nada.
    foreach ($metodo->stmts as $stmt) {
        if (! $stmt instanceof Node\Stmt\Nop) {
            return false;
        }
    }

    return true;
}

/**
 * ¿El método escribe en la base?
 *
 * Es lo que separa lo urgente de lo molesto. Un GET sin token que lista ciudades
 * es una fuga de un catálogo público; un DELETE sin token es alguien borrando
 * datos de un colegio desde fuera.
 */
function escribe(NodeFinder $finder, array $ctrl, string $metodo, array $vistos = []): array
{
    if (isset($vistos[$metodo]) || ! isset($ctrl['metodos'][$metodo])) {
        return [];
    }

    $vistos[$metodo] = true;
    $nodo = $ctrl['metodos'][$metodo];
    $ops  = [];

    $escrituraEstatica = ['insert', 'update', 'delete', 'statement', 'create', 'destroy',
                          'forcedelete', 'truncate', 'insertgetid', 'updateorcreate', 'firstorcreate'];
    $escrituraMetodo   = ['save', 'delete', 'forcedelete', 'update', 'restore', 'attach',
                          'detach', 'sync', 'push', 'insert', 'create', 'truncate', 'updateorcreate'];

    foreach ($finder->findInstanceOf([$nodo], Node\Expr\StaticCall::class) as $ll) {
        if ($ll->name instanceof Node\Identifier
            && in_array(strtolower($ll->name->toString()), $escrituraEstatica, true)) {
            $clase = $ll->class instanceof Node\Name ? $ll->class->toString() : '?';
            $ops[$clase . '::' . $ll->name->toString()] = true;
        }
    }

    foreach ($finder->findInstanceOf([$nodo], Node\Expr\MethodCall::class) as $ll) {
        if (! $ll->name instanceof Node\Identifier) {
            continue;
        }

        $nombre = $ll->name->toString();

        if (in_array(strtolower($nombre), $escrituraMetodo, true)) {
            $ops['->' . $nombre . '()'] = true;
        }

        // Seguir a los auxiliares de la propia clase.
        if ($ll->var instanceof Node\Expr\Variable && $ll->var->name === 'this') {
            foreach (escribe($finder, $ctrl, $nombre, $vistos) as $op) {
                $ops[$op] = true;
            }
        }
    }

    return array_keys($ops);
}

function analizarControlador(string $clase, $parser, NodeFinder $finder, array &$cache): ?array
{
    if (isset($cache[$clase])) {
        return $cache[$clase];
    }

    $ruta = base_path(str_replace('\\', '/', str_replace('App\\', 'app/', $clase)) . '.php');

    if (! is_file($ruta)) {
        return $cache[$clase] = null;
    }

    $ast = $parser->parse(file_get_contents($ruta));

    $metodos = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $metodo) {
        $nombre = $metodo->name->toString();
        $metodos[$nombre] = $metodo;

    }

    $ctrl = [
        'ruta'    => str_replace(base_path() . '/', '', $ruta),
        'metodos' => $metodos,
    ];

    $ctrl['constructorAutentica'] = isset($metodos['__construct'])
        && senalesTransitivas($finder, $ctrl, '__construct') !== [];

    return $cache[$clase] = $ctrl;
}

$resultados = [];

foreach (Route::getRoutes() as $ruta) {
    $accion = $ruta->getActionName();

    if (! str_contains($accion, '@')) {
        continue;   // closures
    }

    [$clase, $metodo] = explode('@', $accion);

    foreach ($ruta->methods() as $verbo) {
        if ($verbo === 'HEAD') {
            continue;
        }

        $ctrl = analizarControlador($clase, $parser, $finder, $analizados);

        if ($ctrl === null) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'FICHERO NO ENCONTRADO', '', ''];
            continue;
        }

        if (! isset($ctrl['metodos'][$metodo])) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'MÉTODO NO ENCONTRADO', '', ''];
            continue;
        }

        // Middleware de ruta. Desde que existe `auth.token`, una ruta puede
        // estar protegida sin que su método haga nada: el guard corre antes.
        // `middleware()` y no `gatherMiddleware()`: el segundo instancia el
        // controlador para leer su middleware, y eso dispara los `fromToken()`
        // de los constructores — el mismo motivo por el que route:list falla.
        $conGuard = array_intersect(
            $ruta->middleware(),
            ['auth.token', 'auth', \App\Http\Middleware\ExigirAutenticacion::class]
        );

        if ($conGuard !== []) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'SÍ',
                             'middleware ' . implode(', ', $conGuard), ''];
            continue;
        }

        if ($ctrl['constructorAutentica']) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'SÍ', 'constructor', ''];
            continue;
        }

        $senales = senalesTransitivas($finder, $ctrl, $metodo);

        if ($senales !== []) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'SÍ', implode(' + ', $senales), ''];
            continue;
        }

        if (estaVacio($ctrl['metodos'][$metodo])) {
            $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'VACÍO', 'el método no hace nada', ''];
            continue;
        }

        $ops = escribe($finder, $ctrl, $metodo);

        $resultados[] = [$verbo, $ruta->uri(), $clase, $metodo, 'NO', '',
                         $ops === [] ? 'lectura' : 'ESCRIBE: ' . implode(', ', array_slice($ops, 0, 3))];
    }
}

usort($resultados, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);

if (in_array('--csv', $argv, true)) {
    $fh = fopen('php://stdout', 'w');
    fputcsv($fh, ['verbo', 'uri', 'controlador', 'metodo', 'autentica', 'como', 'riesgo']);
    foreach ($resultados as $fila) {
        $fila[2] = str_replace('App\\Http\\Controllers\\', '', $fila[2]);
        fputcsv($fh, $fila);
    }
    exit(0);
}

if (in_array('--md', $argv, true)) {
    $sin    = array_values(array_filter($resultados, fn ($r) => $r[4] === 'NO'));
    $con    = array_filter($resultados, fn ($r) => $r[4] === 'SÍ');
    $vacios = array_values(array_filter($resultados, fn ($r) => $r[4] === 'VACÍO'));
    $rotas  = array_values(array_filter($resultados, fn ($r) => ! in_array($r[4], ['SÍ', 'NO', 'VACÍO'], true)));

    $publica = fn ($u) => str_starts_with($u, 'api/login')
        || str_starts_with($u, 'api/password')
        || $u === 'api/tardanzas/login';

    $escriben = array_values(array_filter($sin, fn ($r) => str_starts_with($r[6], 'ESCRIBE')));
    $leen     = array_values(array_filter($sin, fn ($r) => $r[6] === 'lectura'));

    $escPub = array_values(array_filter($escriben, fn ($r) => $publica($r[1])));
    $escRev = array_values(array_filter($escriben, fn ($r) => ! $publica($r[1])));
    $leePub = array_values(array_filter($leen, fn ($r) => $publica($r[1])));
    $leeRev = array_values(array_filter($leen, fn ($r) => ! $publica($r[1])));

    $tabla = function (array $rs, bool $ops = false): string {
        if ($rs === []) {
            return "_Ninguna._\n";
        }

        usort($rs, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);

        $out = '| ✔ | Verbo | Ruta | Controlador · método |' . ($ops ? ' Escribe |' : '') . "\n";
        $out .= '|---|---|---|---|' . ($ops ? '---|' : '') . "\n";

        foreach ($rs as $r) {
            $ctrl = str_replace('App\\Http\\Controllers\\', '', $r[2]);
            $out .= sprintf('| ☐ | `%s` | `%s` | %s::%s |', $r[0], $r[1], $ctrl, $r[3]);
            $out .= $ops ? ' ' . str_replace('ESCRIBE: ', '', $r[6]) . " |\n" : "\n";
        }

        return $out;
    };

    $plantilla = __DIR__ . '/plantillas/auditoria-autenticacion.md';

    echo strtr(file_get_contents($plantilla), [
        '{{TOTAL}}'        => count($resultados),
        '{{CON}}'          => count($con),
        '{{ESCRIBEN}}'     => count($escriben),
        '{{LEEN}}'         => count($leen),
        '{{VACIOS}}'       => count($vacios),
        '{{ROTAS}}'        => count($rotas),
        '{{N_ESC_REV}}'    => count($escRev),
        '{{N_LEE_REV}}'    => count($leeRev),
        '{{T_ESC_REV}}'    => $tabla($escRev, true),
        '{{T_ESC_PUB}}'    => $tabla($escPub, true),
        '{{T_LEE_REV}}'    => $tabla($leeRev),
        '{{T_LEE_PUB}}'    => $tabla($leePub),
        '{{T_VACIOS}}'     => $tabla($vacios),
        '{{T_ROTAS}}'      => $tabla($rotas),
    ]);

    exit(0);
}

$sin    = array_values(array_filter($resultados, fn ($r) => $r[4] === 'NO'));
$con    = array_filter($resultados, fn ($r) => $r[4] === 'SÍ');
$vacios = array_values(array_filter($resultados, fn ($r) => $r[4] === 'VACÍO'));
$raro   = array_values(array_filter($resultados, fn ($r) => ! in_array($r[4], ['SÍ', 'NO', 'VACÍO'], true)));

$escriben = array_values(array_filter($sin, fn ($r) => str_starts_with($r[6], 'ESCRIBE')));
$leen     = array_values(array_filter($sin, fn ($r) => $r[6] === 'lectura'));

printf("Rutas analizadas: %d\n\n", count($resultados));
printf("  Resuelven al usuario:        %3d\n", count($con));
printf("  NO lo resuelven y ESCRIBEN:  %3d   <- lo urgente\n", count($escriben));
printf("  NO lo resuelven, solo leen:  %3d\n", count($leen));
printf("  Método vacío (no hacen nada):%3d\n", count($vacios));
printf("  No se pudieron analizar:     %3d\n\n", count($raro));

function bloque(string $titulo, array $rutas, bool $conOps = false): void
{
    if ($rutas === []) {
        return;
    }

    echo str_repeat('=', 92) . "\n{$titulo}\n" . str_repeat('=', 92) . "\n\n";

    $porCtrl = [];

    foreach ($rutas as $r) {
        $porCtrl[str_replace('App\\Http\\Controllers\\', '', $r[2])][] = $r;
    }

    ksort($porCtrl);

    foreach ($porCtrl as $ctrl => $lista) {
        echo "  {$ctrl}\n";

        foreach ($lista as $r) {
            printf("    %-7s %-50s %-32s%s\n", $r[0], $r[1], $r[3],
                $conOps ? '  ' . $r[6] : '');
        }

        echo "\n";
    }
}

bloque('ESCRIBEN EN LA BASE SIN RESOLVER AL USUARIO', $escriben, true);
bloque('SOLO LEEN, SIN RESOLVER AL USUARIO', $leen);
bloque('MÉTODOS VACÍOS (la ruta existe, el método no hace nada)', $vacios);
bloque('RUTAS REGISTRADAS CUYO MÉTODO NO EXISTE', $raro);
