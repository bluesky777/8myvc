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
// El `try` alrededor del ARRANQUE, y no sólo el manejador de abajo: los ficheros de
// `routes/` se cargan **dentro** de `bootstrap()`, así que un fallo ahí ocurre antes
// de que la línea de abajo llegue a ejecutarse — y adelantarla tampoco vale, porque
// Laravel instala el suyo durante el arranque y pisa el nuestro. Un `try/catch` sí lo
// coge, porque una excepción capturada nunca llega a ser «no capturada». Medido el
// 5 sep 2026 con un fichero de rutas roto: sin esto sale 0, con esto sale 2.
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
} catch (Throwable $e) {
    fwrite(STDERR, "\n!! NO MEDIDO — las rutas NO se regeneraron enteras (falló el arranque)\n\n   "
        .$e->getMessage()."\n\n   No se llegó a mirar nada. Revisa `routes/` y `config/`.\n");
    exit(2);
}

// Sin esto, un fallo aquí sale **por stdout y con código 0**: `bootstrap()` instala
// el manejador de errores de Laravel, que pinta la excepción bonita en la salida
// estándar y deja terminar el proceso normal. Medido el 5 sep 2026 (05 §248).
//
// **Importa aquí más que en ninguna otra de las cuatro, porque este guion ESCRIBE en
// `routes/api/`.** Medido: con un fichero de rutas roto sale 0 y aun así deja ficheros
// escritos. Una regeneración a medias de las rutas con `$?` = 0 es lo peor que puede
// hacer una herramienta de esta carpeta.
//
// Sale **2** porque no es un hallazgo ni un fallo de la herramienta: es que **no se
// pudo mirar**. Es la guardia de `salud-de-las-definitivas.php` y `fase-cero`.
//
// **Lo que NO coge, y hay que saberlo**: un error de SINTAXIS en un fichero que se
// cargue después es un fatal de PHP, y ésos los recoge el `register_shutdown_function`
// de Laravel, no un `set_exception_handler`. Medido: con un `.php` roto a propósito
// esto sigue saliendo 0. Coge los `Throwable` —que es de lo que se hablaba— y no los
// fatales de compilación.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n!! NO MEDIDO — las rutas NO se regeneraron enteras\n\n   ".$e->getMessage()."\n\n"
        ."   `routes/api/` puede haber quedado a medio escribir: compruébalo con `git status`\n   ANTES de commitear nada.\n");
    exit(2);
});


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
