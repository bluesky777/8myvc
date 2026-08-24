<?php

namespace Tests\Barrido;

use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Qué cuesta el gemelo de la raíz — el que `2837171` dejó a propósito sin tocar.
 *
 * `app/Http/Controllers/BolfinalesController.php` es una copia de
 * `Informes/BolfinalesController` con **los mismos dos bucles anidados** que
 * daban el 504, y el arreglo de BOL-1 **no lo tocó**, con motivo escrito: «cada
 * una necesita su propia medición y sus propias redes». Esto es esa medición.
 *
 *     docker exec -w /app/.worktrees/12 -e DB_TEST_DATABASE=simonbolivar_testing_12 \
 *         8myvc-app-1 php artisan test --group=barrido --filter=CosteDelGemeloDeLaRaizTest
 *
 * ## Lo que hace a este camino distinto del que se arregló, y peor
 *
 * **No tiene ruta propia.** Se alcanza por `new BolfinalesController` desde
 * `CertificadosEstudioController`, en sus dos únicos métodos, y los dos están
 * enrutados y vivos con `auth.personal`:
 *
 *     GET certificados-estudio/certificado-alumno/{grupo_id}   -> :22
 *     GET certificados-estudio/certificado-grupo/{grupo_id}    -> :39
 *
 * **Y las dos acaban en 500**, por una vista `certificados.estudio` que no
 * existe (`MuestreoDeLecturasConContextoTest`). El 500 **no ahorra el trabajo**:
 * `$bol->detailedNotasGrupo(...)` corre entero y sólo después revienta
 * `View::make`. O sea que este camino **paga las consultas de un boletín final
 * completo y no devuelve nada**, en los dieciséis colegios.
 *
 * Por eso el entregable de este test no es «cuánto se puede optimizar»: es
 * **cuánto cuesta hoy una respuesta que nadie puede usar**. Si el número es
 * grande, la decisión barata no es agregar las consultas — es que este camino
 * no debería llegar hasta ellas.
 *
 * ## Imprime, no comprueba (salvo dos cosas)
 *
 * El número de consultas depende del seed, así que la cota iría al aire. Lo que
 * sí se fija son las dos cosas que **no** dependen de la máquina y que, si
 * cambian, invalidan la lectura de arriba:
 *
 *  - que las dos rutas siguen dando **500** — si un día dan 200, este documento
 *    miente y hay que releerlo;
 *  - que el oyente **contó algo**. Un `DB::listen` que no se engancha cuenta
 *    cero, y cero es el mejor número posible en cualquier cota del tipo «no más
 *    de N»: el test pasaría igual sin medir nada. Es la trampa de la §186.1 y
 *    aquí es la que decidiría el resultado entero.
 */
#[Group('barrido')]
class CosteDelGemeloDeLaRaizTest extends CasoDeContrato
{
    /**
     * Las rutas de este barrido, y con qué se comparan.
     *
     * La tercera **no es del gemelo**: es la que `2837171` ya arregló, y está
     * aquí para que el número de las otras dos tenga con qué compararse **medido
     * en la misma corrida y en la misma máquina**. Sin ella, «755 consultas» es
     * una cifra de otro día que no se puede restar de nada.
     */
    private const RUTAS = [
        ['GET',  '/api/certificados-estudio/certificado-grupo/%d',   500, 'gemelo de la raíz — grupo'],
        ['GET',  '/api/certificados-estudio/certificado-alumno/%d',  500, 'gemelo de la raíz — alumno'],
        ['PUT',  '/api/bolfinales/detailed-notas-year-group/%d',     200, 'el YA arreglado (referencia)'],
    ];

    public function test_cuanto_cuesta_el_camino_que_acaba_en_500(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$grupo, $token] = $this->grupoYPersonal();

        $forma = DB::selectOne(
            'SELECT
                (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                  WHERE m.grupo_id = ? AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR","ASIS","PREM")) AS alumnos,
                (SELECT COUNT(*) FROM asignaturas a
                  WHERE a.grupo_id = ? AND a.deleted_at IS NULL) AS asignaturas',
            [$grupo->id, $grupo->id]
        );

        // **Un solo oyente, fuera de todo bucle.** `DB::listen` no se puede quitar
        // —no hay `unlisten`—, así que uno por ruta dejaría vivos los anteriores
        // sumando en las mismas variables capturadas por referencia. Eso ya dio
        // aquí un «816 donde había 408», un factor 2 exacto y perfectamente
        // creíble (ver la cabecera de `CosteDelBoletinFinalTest`).
        $conteo = ['total' => 0, 'periodos' => 0];
        $porFirma = [];

        DB::listen(function ($consulta) use (&$conteo, &$porFirma) {
            $conteo['total']++;

            if (self::esDeLosPeriodosDelAnio($consulta->sql)) {
                $conteo['periodos']++;
            }

            $firma = preg_replace('/\s+/', ' ', substr(ltrim($consulta->sql), 0, 60));
            $porFirma[$firma] = ($porFirma[$firma] ?? 0) + 1;
        });

        $informe = '';
        $medidas = [];

        foreach (self::RUTAS as [$verbo, $plantilla, $esperado, $etiqueta]) {
            $url = sprintf($plantilla, $grupo->id);

            // Una pasada en frío que se descarta, por ruta: la primera paga el
            // primer plan de cada consulta. No se descarta el CONTEO —las
            // consultas no se abaratan al repetirse—, se descarta para que el
            // reparto de firmas de abajo sea el de una petición en régimen.
            $this->llamar($verbo, $url, $token);

            $conteo = ['total' => 0, 'periodos' => 0];
            $porFirma = [];

            $desde = hrtime(true);
            $r = $this->llamar($verbo, $url, $token);
            $ms = (hrtime(true) - $desde) / 1e6;

            $r->assertStatus($esperado);

            $medidas[$etiqueta] = $conteo;

            /** @var array<string, int> $porFirma */
            arsort($porFirma);
            $reparto = '';
            $mostradas = 0;

            foreach ($porFirma as $firma => $veces) {
                if ($mostradas++ >= 5) {
                    break;
                }
                $reparto .= sprintf("        %6d  %s…\n", $veces, $firma);
            }

            $informe .= sprintf(
                "  %-32s  %s %s\n".
                "        %6d  consultas   (%d de «los periodos del año»)   %.0f ms   -> %d\n%s",
                $etiqueta, $verbo, $url,
                $conteo['total'], $conteo['periodos'], $ms, $r->getStatusCode(),
                $reparto
            );
        }

        $carga = trim(explode(' ', (string) @file_get_contents('/proc/loadavg'))[0]);

        fwrite(STDERR, sprintf(
            "\n%s\n".
            "  grupo %d · %d alumnos × %d asignaturas = %d combinaciones\n".
            "  base `%s` · carga %s\n".
            "  %s\n%s  %s\n\n",
            'Coste del gemelo de la raíz, y del que ya se arregló, en la MISMA corrida',
            $grupo->id, $forma->alumnos, $forma->asignaturas,
            $forma->alumnos * $forma->asignaturas,
            DB::connection()->getDatabaseName(), $carga,
            str_repeat('-', 78), $informe, str_repeat('-', 78)
        ));

        // **La mitad que impide el falso verde.** Sin esto, un oyente que no se
        // enganche imprime ceros y el test pasa igual.
        foreach ($medidas as $etiqueta => $conteo) {
            $this->assertGreaterThan(0, $conteo['total'],
                "El oyente no contó ninguna consulta en «{$etiqueta}»: el informe de arriba no mide nada.");
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function llamar(string $verbo, string $url, string $token)
    {
        $cabeceras = ['Authorization' => 'Bearer '.$token];

        return $verbo === 'GET'
            ? $this->getJson($url, $cabeceras)
            : $this->putJson($url, [], $cabeceras);
    }

    /**
     * «Los periodos del año», en sus tres formas reales.
     *
     * **Copiado de `Tests\Contrato\BoletinFinalConsultaInvarianteTest`, donde está
     * el porqué de cada una de las tres condiciones y el test que las ejerce.**
     * Se copia y no se comparte a propósito: allí es `private static` de un test
     * de contrato y sacarlo a un sitio común lo pondría a merced de este barrido,
     * que es lo contrario de lo que hace falta. **Si aquél cambia, éste hay que
     * cambiarlo a mano** — y por eso queda dicho aquí.
     *
     * Lo que decide es la tercera: la invariante **no une con nada**, y sin esa
     * condición la consulta de comportamiento —`FROM periodos p LEFT JOIN
     * nota_comportamiento`— entra y añade una por alumno.
     *
     * Y aquí hace falta la forma de Eloquent, no la del SQL crudo: **el gemelo de
     * la raíz escribe estas tres consultas con Eloquent**, que es exactamente el
     * caso que a la primera versión de aquel predicado se le escapaba entero.
     */
    private static function esDeLosPeriodosDelAnio(string $sql): bool
    {
        return preg_match('~\bfrom\s+`?periodos`?\b~i', $sql) === 1
            && stripos($sql, 'year_id') !== false
            && stripos($sql, 'join') === false;
    }
}
