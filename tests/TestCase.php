<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrarRutasEjecutadas();
    }

    /**
     * Anota en un fichero cada ruta que la suite ejecuta de verdad.
     *
     * Existe porque "qué rutas cubren los tests" no se puede contestar leyendo
     * los tests. Las URLs se construyen interpolando —"api/boletines/{$grupo}"—,
     * así que buscarlas con grep da una lista con agujeros en los dos sentidos:
     * se pierde lo interpolado y cuenta como cubierto lo que solo aparece dentro
     * de un comentario o de un `assertStatus(404)`.
     *
     * Solo se activa con la variable puesta, y el informe lo saca
     * tools/cobertura-de-rutas.php:
     *
     *   COBERTURA_RUTAS=/tmp/rutas-tocadas.txt php artisan test --testsuite=Contrato
     *   php tools/cobertura-de-rutas.php /tmp/rutas-tocadas.txt
     *
     * Se anota también QUÉ test la ejecutó, y esa columna es la que hace útil
     * al fichero. Sin ella la respuesta es «el 99% de las rutas se ejecutan», que
     * es cierto y no sirve de nada: `AutorizacionTest` hace pasar las 539 por el
     * router para su snapshot de guards. Con ella se puede preguntar lo que de
     * verdad se quería saber, que es qué rutas no toca nadie MÁS que los tests de
     * barrido — esas son las que no tienen la respuesta comprobada por nadie.
     *
     * Se usa `nameWithDataSet()`, no `name()`, y la diferencia importa: lo que
     * distingue un barrido de un test de contrato normal no es cuántas rutas
     * toca la clase, es cuántas toca UNA ejecución. Un test parametrizado que
     * mira 66 respuestas de una en una toca una por caso; `AutorizacionTest`
     * toca trescientas en el mismo. Sin el data set los dos se cuentan igual y
     * el muestreo se descarta a sí mismo.
     *
     * Se escribe con FILE_APPEND y LOCK_EX porque el fichero lo comparten todos
     * los tests de la corrida, y `--parallel` los reparte en varios procesos.
     */
    private function registrarRutasEjecutadas(): void
    {
        $destino = getenv('COBERTURA_RUTAS');

        if ($destino === false || $destino === '') {
            return;
        }

        $test = str_replace('Tests\\', '', static::class).'::'.$this->nameWithDataSet();

        Event::listen(function (RouteMatched $evento) use ($destino, $test) {
            file_put_contents(
                $destino,
                $test."\t".$evento->request->method().' '.$evento->route->uri()."\n",
                FILE_APPEND | LOCK_EX
            );
        });
    }
}
