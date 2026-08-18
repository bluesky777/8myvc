<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contrato de enrutado.
 *
 * La Fase 1 cambió `AdvancedRoute` por rutas explícitas escritas a mano en
 * routes/api/. Nada impide que un merge desordenado borre una, o que alguien
 * mueva una ruta con `{id}` por encima de una literal y la tape.
 *
 * Comparar el CONJUNTO de rutas no basta: Laravel sirve la primera que casa, así
 * que reordenar puede tapar `puestos/detailed` con `puestos/{id}` sin que el
 * conjunto cambie en absoluto. Lo que se guarda aquí es, para cada URI literal,
 * QUÉ acción la atiende.
 *
 * No hereda de CasoDeContrato: no toca la base de datos.
 */
class RutasTest extends TestCase
{
    public function test_cada_uri_la_atiende_la_misma_accion(): void
    {
        $resueltas = [];

        foreach (Route::getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                $resueltas[$verbo . ' ' . $ruta->uri()] = $ruta->getActionName();
            }
        }

        ksort($resueltas);

        $ruta = __DIR__ . '/Snapshots/rutas.json';

        if (! file_exists($ruta)) {
            file_put_contents(
                $ruta,
                json_encode($resueltas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            fwrite(STDERR, "\n  ↳ snapshot de rutas creado con " . count($resueltas) . " entradas\n");

            $this->addToAssertionCount(1);

            return;
        }

        $esperadas = json_decode(file_get_contents($ruta), true);

        $faltan = array_diff_key($esperadas, $resueltas);
        $sobran = array_diff_key($resueltas, $esperadas);

        $this->assertSame([], $faltan, 'Desaparecieron rutas: ' . implode(', ', array_keys($faltan)));
        $this->assertSame([], $sobran, 'Aparecieron rutas nuevas sin actualizar el snapshot: ' . implode(', ', array_keys($sobran)));

        // Mismo conjunto de URIs: ahora, que cada una siga atendida por lo mismo.
        $cambiadas = [];

        foreach ($esperadas as $uri => $accion) {
            if (($resueltas[$uri] ?? null) !== $accion) {
                $cambiadas[$uri] = $accion . ' → ' . ($resueltas[$uri] ?? 'nada');
            }
        }

        $this->assertSame([], $cambiadas, 'Rutas que ahora las atiende otra acción');
    }

    public function test_no_hay_rutas_duplicadas(): void
    {
        $vistas = [];
        $duplicadas = [];

        foreach (Route::getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                $clave = $verbo . ' ' . $ruta->uri();

                if (isset($vistas[$clave])) {
                    $duplicadas[$clave] = true;
                }

                $vistas[$clave] = true;
            }
        }

        // AdvancedRoute registraba cada ruta dos veces; la tabla tenía ~1.076
        // entradas para 538 rutas. Que no vuelva a pasar sin que nos enteremos.
        $this->assertSame([], array_keys($duplicadas), 'Hay rutas registradas dos veces');
    }
}
