<?php

namespace Tests\Barrido;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contrato\CasoDeContrato;

/**
 * Cuánto cuesta `PUT bolfinales/detailed-notas-year-group`, antes y después de
 * sacar la consulta invariante del bucle.
 *
 * **Imprime, no comprueba.** El candado del arreglo es
 * `Tests\Contrato\BoletinFinalConsultaInvarianteTest`, que cuenta consultas y no
 * depende de la máquina; esto da el tiempo, que sí depende, y por eso vive en el
 * grupo `barrido` y no en la suite.
 *
 *     docker exec -w /app/.worktrees/<sufijo> -e DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
 *         8myvc-app-1 php artisan test --group=barrido --filter=CosteDelBoletinFinalTest
 *
 * ## Cómo se usa para comparar dos estados del código
 *
 * No se puede alternar en la misma ventana —el código está arreglado o no lo
 * está—, así que **se alternan las corridas**: A, B, A, B, con la máquina quieta y
 * la carga impresa. Es lo mejor que se puede hacer aquí, y **hay que decir que no
 * es lo mismo** que alternar bloques dentro de una corrida, que es lo que se hizo
 * con `notas/lote`.
 *
 * Y por eso el entregable es **la razón y las consultas**, no los milisegundos: la
 * misma suite de este repositorio tardó 2.132 s y 593 s la misma noche.
 */
#[Group('barrido')]
class CosteDelBoletinFinalTest extends CasoDeContrato
{
    private const FIRMA = 'FROM periodos WHERE year_id';

    public function test_cuanto_cuesta_el_boletin_final_de_un_grupo(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $pasadas = max(3, (int) (getenv('BOLETIN_PASADAS') ?: 7));

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

        $tiempos = [];
        $totales = [];
        $invariantes = [];

        // **El oyente se registra UNA vez, fuera del bucle, y esto no es estilo: la
        // primera versión lo registraba dentro y contaba el doble.**
        //
        // `DB::listen` no se puede quitar —no hay `unlisten`—, así que un oyente por
        // pasada deja todos los anteriores vivos. Y como los contadores se
        // reasignaban al principio de cada iteración, **los oyentes viejos seguían
        // sumando en las MISMAS variables**: PHP reutiliza el slot y el cierre las
        // capturó por referencia. En la pasada 1 había dos oyentes, así que el
        // informe decía **816 consultas invariantes donde había 408: un factor 2
        // exacto, y un número perfectamente creíble.**
        //
        // Lo cazó tener **dos medidas de lo mismo**: el test de contrato
        // (`BoletinFinalConsultaInvarianteTest`) cuenta lo mismo con un solo oyente
        // y daba la mitad. Sin esa segunda medida, el 816 se publica.
        $conteo = ['total' => 0, 'invariante' => 0];
        $porFirma = [];

        DB::listen(function ($consulta) use (&$conteo, &$porFirma) {
            $conteo['total']++;

            if (str_contains($consulta->sql, self::FIRMA)) {
                $conteo['invariante']++;
            }

            // **De dónde sale lo que queda**, que es la otra mitad del entregable:
            // sacar una consulta del bucle no sirve de nada si las otras 3.354 son
            // el problema. La firma es la primera tabla del `FROM` más el trozo
            // inicial: agrupa las repeticiones sin necesitar normalizar el SQL.
            $firma = preg_replace('/\s+/', ' ', substr(ltrim($consulta->sql), 0, 60));
            $porFirma[$firma] = ($porFirma[$firma] ?? 0) + 1;
        });

        // La primera pasada se descarta: paga el primer plan de cada consulta y el
        // buffer pool frío. Se ejecuta igual, para que la siguiente encuentre el
        // terreno como lo encontrarán las demás.
        for ($i = 0; $i <= $pasadas; $i++) {
            $conteo = ['total' => 0, 'invariante' => 0];

            // **También el histograma se resetea, y también costó un número.** Sin
            // esto acumulaba las cuatro pasadas y el reparto sumaba 13.421 debajo de
            // un total de 3.355 — cuatro veces todo, con la cabecera diciendo «de la
            // última pasada». Es el mismo error que el del oyente, un nivel más
            // abajo: lo que se lee por pasada hay que vaciarlo por pasada.
            $porFirma = [];

            $desde = hrtime(true);

            $this->putJson('/api/bolfinales/detailed-notas-year-group/'.$grupo->id, [],
                ['Authorization' => 'Bearer '.$token])->assertStatus(200);

            $ms = (hrtime(true) - $desde) / 1e6;

            if ($i === 0) {
                continue;
            }

            $tiempos[] = $ms;
            $totales[] = $conteo['total'];
            $invariantes[] = $conteo['invariante'];
        }

        sort($tiempos);
        $mitad = intdiv(count($tiempos), 2);
        $mediana = count($tiempos) % 2 === 1
            ? $tiempos[$mitad]
            : ($tiempos[$mitad - 1] + $tiempos[$mitad]) / 2;

        $carga = trim(explode(' ', (string) @file_get_contents('/proc/loadavg'))[0]);

        arsort($porFirma);
        $reparto = '';
        $mostradas = 0;

        foreach ($porFirma as $firma => $veces) {
            if ($mostradas++ >= 8) {
                break;
            }

            $reparto .= sprintf("    %6d  %s…\n", $veces, $firma);
        }

        $reparto .= sprintf("    %6d  (%d firmas distintas en total)\n",
            array_sum($porFirma), count($porFirma));

        fwrite(STDERR, sprintf(
            "\n%s\n".
            "  grupo %d · %d alumnos × %d asignaturas = %d combinaciones\n".
            "  base `%s` · %d pasadas medidas + 1 descartada · carga %s\n".
            "  %s\n".
            "  mediana %.1f ms   (mejor %.1f · peor %.1f)\n".
            "  consultas de la petición: %d\n".
            "  de ésas, `%s`: %d\n".
            "  de dónde salen (las 8 firmas más repetidas, de UNA petición):\n%s%s\n\n",
            'Coste de `PUT bolfinales/detailed-notas-year-group`',
            $grupo->id, $forma->alumnos, $forma->asignaturas,
            $forma->alumnos * $forma->asignaturas,
            DB::connection()->getDatabaseName(), count($tiempos), $carga,
            str_repeat('-', 74),
            $mediana, min($tiempos), max($tiempos),
            (int) $totales[0], self::FIRMA, (int) $invariantes[0],
            $reparto, str_repeat('-', 74)
        ));

        $this->assertCount($pasadas, $tiempos,
            'El informe no completó sus pasadas: el número de arriba no es el que dice la cabecera.');
    }
}
