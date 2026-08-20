<?php

namespace Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrarRutasEjecutadas();
        $this->registrarConsultasEjecutadas();
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
     * tools/cobertura-de-rutas.py:
     *
     *   COBERTURA_RUTAS=/tmp/rutas-tocadas.txt php artisan test --testsuite=Contrato
     *   python3 tools/cobertura-de-rutas.py /tmp/rutas.json /tmp/rutas-tocadas.txt
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

    /**
     * Anota cada consulta que la suite ejecuta, para poder pasarle EXPLAIN.
     *
     * Es el paso 12 del plan de rendimiento —los índices— hecho por donde el
     * plan pide: midiendo. El plan dice «no adivines: EXPLAIN sobre lo que
     * salga del log de consultas lentas», y hasta que ese log lleve una semana
     * en producción no hay nada que explicar. Mientras tanto la suite ya
     * ejecuta 208 rutas con la respuesta comprobada, y sus consultas son las
     * mismas que las de producción: lo único que cambia es cuántas filas hay.
     *
     * **Y lo que se busca no depende de cuántas filas haya.** `possible_keys`
     * vacío en el EXPLAIN significa que para esa consulta no existe ningún
     * índice que MySQL pudiera considerar; eso es una propiedad del esquema, no
     * del volumen. Con 1,16 millones de filas en `notas` la diferencia entre
     * tenerlo y no tenerlo son segundos, pero el hecho se ve igual con el seed.
     *
     * Solo se activa con la variable puesta:
     *
     *   docker exec 8myvc-app-1 rm -f /tmp/consultas.jsonl
     *   docker exec -e EXPLICAR_CONSULTAS=/tmp/consultas.jsonl 8myvc-app-1 \
     *       php artisan test --testsuite=Contrato
     *   docker exec 8myvc-app-1 php tools/indices-que-faltan.php /tmp/consultas.jsonl
     *
     * Se anota la consulta con sus valores, y aquí sí: la base de tests es el
     * seed anonimizado, no la de nadie. En producción esa decisión es la
     * contraria, y por eso App\Support\ConsultasLentas los omite por defecto.
     */
    private function registrarConsultasEjecutadas(): void
    {
        $destino = getenv('EXPLICAR_CONSULTAS');

        if ($destino === false || $destino === '') {
            return;
        }

        // Repetidas dentro del mismo proceso no se vuelven a escribir: la suite
        // hace la misma consulta cientos de veces y el fichero crecería a
        // decenas de megas para decir lo mismo. Entre procesos sí se repiten
        // —`--parallel`—, y las deduplica el que lee.
        $vistas = [];

        DB::listen(function (QueryExecuted $consulta) use ($destino, &$vistas) {
            $huella = md5($consulta->sql);

            if (isset($vistas[$huella])) {
                return;
            }

            $vistas[$huella] = true;

            $ruta = request()->route();

            file_put_contents($destino, json_encode([
                'sql' => $consulta->sql,
                'valores' => array_map(
                    fn ($valor) => is_scalar($valor) || $valor === null ? $valor : (string) $valor,
                    $consulta->bindings
                ),
                'ms' => round($consulta->time, 2),
                'origen' => $ruta ? request()->method().' '.$ruta->uri() : null,
                'test' => str_replace('Tests\\', '', static::class),
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        });
    }
}
