<?php

/**
 * Genera los archivos de rutas explícitas que reemplazan a AdvancedRoute.
 *
 * Lee la tabla de rutas REAL que Laravel tiene registrada ahora mismo (con
 * AdvancedRoute todavía activo) y emite el PHP equivalente, en el mismo orden de
 * registro. Se genera desde la tabla real y no desde la reflexión para que lo
 * emitido sea, por construcción, lo que hay hoy.
 *
 * Uso:
 *   php tools/route-emit.php
 *
 * Escribe routes/api/*.php agrupando por dominio. No toca routes/api.php: eso se
 * hace a mano después de revisar lo generado.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/*
 * Reparto por dominio, siguiendo las carpetas que ya existen en
 * app/Http/Controllers. La clave es un fragmento del nombre de la clase.
 */
$dominios = [
    'auth'         => ['LoginController', 'RemindersController'],
    'alumnos'      => ['AlumnosController', 'Alumnos\\', 'AcudientesController', 'BuscarController',
                       'Matriculas\\', 'CarteraController', 'DetallesController', 'PromovidosController'],
    'academico'    => ['AreasController', 'MateriasController', 'AsignaturasController', 'UnidadesController',
                       'SubunidadesController', 'NotasController', 'EditnotaController', 'NotaComportamientoController',
                       'DefinitivasPeriodosController', 'EscalasDeValoracionController', 'FrasesController',
                       'FrasesAsignaturaController', 'PlanillasController', 'BolfinalesController'],
    'estructura'   => ['GradosController', 'GruposController', 'NivelesEducativosController', 'YearsController',
                       'PeriodosController', 'ProfesoresController', 'ContratosController'],
    'disciplina'   => ['Disciplina\\', 'AusenciasController', 'DefinicionesComportamientoController',
                       'ChangeAskedController', 'ChangeAskedAssignmentController'],
    'informes'     => ['Informes\\', 'Historiales\\', 'CertificadosEstudioController', 'ConfigCertificadosController'],
    'piars'        => ['Piars\\'],
    'perfiles'     => ['Perfiles\\'],
    'votaciones'   => ['Vt'],
    'actividades'  => ['Actividades\\'],
    'tardanzas'    => ['Tardanzas\\', 'AppMobile\\', 'AplicacionDescargas\\'],
    'admin'        => ['UsersController', 'RolesController', 'PermissionsController', 'BitacorasController',
                       'CambiarUsuarios\\', 'EventosController', 'UniformesController'],
    'catalogos'    => ['PaisesController', 'CiudadesController', 'TipoDocumentoController', 'EstadosCivilesController',
                       'ParentescosController'],
];

function dominioDe(string $clase, array $dominios): string
{
    foreach ($dominios as $nombre => $fragmentos) {
        foreach ($fragmentos as $fragmento) {
            if (str_contains($clase, $fragmento)) {
                return $nombre;
            }
        }
    }

    return 'otros';
}

$porDominio = [];
$sinClasificar = [];

foreach (Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $ruta) {
    $accion = $ruta->getActionName();

    if ($accion === 'Closure' || ! str_contains($accion, '@')) {
        continue; // routes/web.php, se deja como está
    }

    [$clase, $metodo] = explode('@', $accion);

    $uri = $ruta->uri();

    if (! str_starts_with($uri, 'api/')) {
        continue;
    }

    $uri = substr($uri, 4); // el prefijo 'api' lo pone el RouteServiceProvider

    $verbos = array_values(array_diff($ruta->methods(), ['HEAD']));

    $dominio = dominioDe($clase, $dominios);

    if ($dominio === 'otros') {
        $sinClasificar[$clase] = true;
    }

    $porDominio[$dominio][] = [
        'verbos' => $verbos,
        'uri' => $uri,
        'clase' => $clase,
        'metodo' => $metodo,
    ];
}

$destino = __DIR__ . '/../routes/api';

if (! is_dir($destino)) {
    mkdir($destino, 0755, true);
}

$totalEmitidas = 0;

foreach ($porDominio as $dominio => $rutas) {
    // Un use por clase, en orden alfabético.
    $clases = array_values(array_unique(array_column($rutas, 'clase')));
    sort($clases);

    $php = "<?php\n\n";
    $php .= "use Illuminate\\Support\\Facades\\Route;\n";

    foreach ($clases as $clase) {
        $php .= "use $clase;\n";
    }

    $php .= "\n/*\n";
    $php .= "|--------------------------------------------------------------------------\n";
    $php .= "| Rutas: $dominio\n";
    $php .= "|--------------------------------------------------------------------------\n";
    $php .= "|\n";
    $php .= "| Generado por tools/route-emit.php a partir de la tabla de rutas que\n";
    $php .= "| AdvancedRoute registraba. El orden es el de registro y es significativo:\n";
    $php .= "| las rutas sin parámetros van antes que las que llevan {param} para que no\n";
    $php .= "| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.\n";
    $php .= "|\n";
    $php .= "*/\n\n";

    $claseAnterior = null;

    foreach ($rutas as $r) {
        if ($r['clase'] !== $claseAnterior) {
            $php .= ($claseAnterior === null ? '' : "\n") . '// ' . class_basename($r['clase']) . "\n";
            $claseAnterior = $r['clase'];
        }

        $corto = class_basename($r['clase']);

        foreach ($r['verbos'] as $verbo) {
            $fn = strtolower($verbo);
            $php .= sprintf(
                "Route::%s('%s', [%s::class, '%s']);\n",
                $fn,
                $r['uri'],
                $corto,
                $r['metodo']
            );
            $totalEmitidas++;
        }
    }

    file_put_contents("$destino/$dominio.php", $php);
    printf("  %-12s %3d rutas\n", $dominio, count($rutas));
}

echo "\n  total emitidas: $totalEmitidas\n";

if ($sinClasificar) {
    echo "\n  SIN CLASIFICAR (van a otros.php):\n";
    foreach (array_keys($sinClasificar) as $clase) {
        echo "    - $clase\n";
    }
}
