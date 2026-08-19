<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Los facades se importan por su nombre completo, no por el alias de raíz.
 *
 * `use DB;` funciona hoy porque `config/app.php` mantiene un array `aliases`
 * que registra un `class_alias` global por cada uno. Es una capa que Laravel
 * arrastra desde la versión 4 y que ya no crea en las aplicaciones nuevas: el
 * `config/app.php` que genera Laravel 13 no trae ese array. El día que se
 * retire —o el día que alguien limpie ese fichero copiando uno moderno— dejan
 * de resolver todos a la vez, y el fallo no es un aviso: es «Class DB not
 * found» en cada petición que pase por ahí.
 *
 * `use Illuminate\Support\Facades\DB;` es la misma clase, escrita de forma que
 * no dependa de esa capa. Las llamadas no cambian: siguen siendo `DB::select()`.
 *
 * Este test no arranca Laravel. Lee el código con el tokenizador de PHP, que es
 * lo que distingue un `Auth::attempt()` de verdad de uno escrito dentro de un
 * comentario — con grep salían seis ficheros que no tenían nada.
 */
class AliasDeFacadesTest extends TestCase
{
    private const RAIZ = __DIR__.'/../..';

    /** Carpetas que sí controlamos. `vendor/` y `public/` quedan fuera. */
    private const CARPETAS = ['app', 'config', 'database', 'routes', 'tests', 'tools'];

    public function test_ningun_fichero_depende_del_array_de_alias(): void
    {
        $alias = $this->aliasRegistrados();
        $hallazgos = [];

        foreach ($this->ficherosPhp() as $ruta) {
            foreach ($this->referenciasGlobales($ruta, $alias) as [$linea, $nombre, $motivo]) {
                $corta = str_replace(self::RAIZ.'/', '', $ruta);
                $hallazgos[] = "  $corta:$linea  $nombre  ($motivo)";
            }
        }

        sort($hallazgos);

        $this->assertSame([], $hallazgos, implode("\n", array_merge(
            [count($hallazgos).' referencias resuelven por el array `aliases` de config/app.php:', ''],
            $hallazgos,
            [
                '',
                'Importa el nombre completo. El mapa está en config/app.php, y hay',
                'una herramienta que lo aplica sola sin tocar las llamadas:',
                '',
                '    php tools/imports-de-facades.php --dry-run',
            ]
        )));
    }

    /**
     * Y las vistas, que es donde queda la exposición de verdad.
     *
     * Una plantilla Blade se compila a PHP sin namespace, así que `Route::has()`
     * dentro de una vista resuelve al nombre global —al alias— y ningún import
     * arriba del fichero la cubre. Ahí el nombre completo hay que escribirlo
     * entero en la llamada.
     */
    public function test_ninguna_vista_depende_del_array_de_alias(): void
    {
        $alias = $this->aliasRegistrados();
        $hallazgos = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::RAIZ.'/resources', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $fichero) {
            if (! str_ends_with($fichero->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (file($fichero->getPathname()) as $numero => $linea) {
                foreach ($alias as $nombre) {
                    // Sin barra delante y sin nada pegado por la izquierda: un
                    // `\Illuminate\...\Route::` o un `$objeto::` no cuentan.
                    if (preg_match('/(?<![\w$>:\\\\])'.preg_quote($nombre, '/').'::/', $linea)) {
                        $corta = str_replace(self::RAIZ.'/', '', $fichero->getPathname());
                        $hallazgos[] = '  '.$corta.':'.($numero + 1).'  '.$nombre.'::';
                    }
                }
            }
        }

        sort($hallazgos);

        $this->assertSame([], $hallazgos, implode("\n", array_merge(
            [count($hallazgos).' llamadas en vistas resuelven por el array `aliases`:', ''],
            $hallazgos,
            [
                '',
                'En una vista no vale importar: escribe el nombre completo en la llamada.',
                '',
                '    @if (Route::has(...))  ->  @if (\Illuminate\Support\Facades\Route::has(...))',
            ]
        )));
    }

    /**
     * La lista sale de config/app.php, no de una copia aquí dentro: si mañana
     * alguien añade un alias, este test lo vigila sin que nadie se acuerde.
     */
    private function aliasRegistrados(): array
    {
        $config = require self::RAIZ.'/config/app.php';

        return array_keys($config['aliases'] ?? []);
    }

    private function ficherosPhp(): array
    {
        $ficheros = [];

        foreach (self::CARPETAS as $carpeta) {
            $directorio = self::RAIZ.'/'.$carpeta;

            if (! is_dir($directorio)) {
                continue;
            }

            $iterador = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterador as $fichero) {
                if ($fichero->getExtension() === 'php') {
                    $ficheros[] = $fichero->getPathname();
                }
            }
        }

        sort($ficheros);

        return $ficheros;
    }

    /**
     * Dos formas, y las dos dependen del array de alias:
     *
     *   use DB;      el import trae el nombre global al fichero
     *   \DB::table() la llamada va al nombre global directamente
     *
     * Un `Illuminate\Support\Facades\DB` no es ninguna de las dos: tiene más de
     * un segmento y no pasa por el alias.
     */
    private function referenciasGlobales(string $ruta, array $alias): array
    {
        $tokens = token_get_all(file_get_contents($ruta));
        $hallazgos = [];
        $enUse = false;
        $segmentos = 0;
        $nombre = null;
        $linea = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$tipo, $texto, $numeroDeLinea] = $token;

                // `use` de import: solo en el cuerpo del fichero. Los `use` de
                // cierre (`function () use ($x)`) y los de trait van seguidos de
                // algo que no es un nombre, así que se descartan solos.
                if ($tipo === T_USE) {
                    $enUse = true;
                    $segmentos = 0;
                    $nombre = null;

                    continue;
                }

                if ($tipo === T_WHITESPACE || $tipo === T_COMMENT || $tipo === T_DOC_COMMENT) {
                    continue;
                }

                if ($enUse) {
                    if ($tipo === T_STRING) {
                        $segmentos++;
                        $nombre = $texto;
                        $linea = $numeroDeLinea;
                    } elseif ($tipo === T_NAME_QUALIFIED || $tipo === T_NAME_FULLY_QUALIFIED) {
                        $segmentos = 2; // tiene barras: no es un alias de raíz
                    } else {
                        $enUse = false;
                    }

                    continue;
                }

                // `\DB::` — nombre absoluto de un solo segmento.
                if ($tipo === T_NAME_FULLY_QUALIFIED) {
                    $sinBarra = ltrim($texto, '\\');

                    if (! str_contains($sinBarra, '\\') && in_array($sinBarra, $alias, true)) {
                        $hallazgos[] = [$numeroDeLinea, '\\'.$sinBarra, 'nombre global'];
                    }
                }

                continue;
            }

            // Un carácter suelto. `;` cierra el `use`.
            if ($enUse && $token === ';') {
                if ($segmentos === 1 && in_array($nombre, $alias, true)) {
                    $hallazgos[] = [$linea, "use $nombre;", 'alias de raíz'];
                }

                $enUse = false;
            } elseif ($enUse && $token !== '\\') {
                $enUse = false;
            }
        }

        return $hallazgos;
    }
}
