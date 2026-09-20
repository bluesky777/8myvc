<?php

namespace Tests\Contrato;

use Illuminate\Http\Request;
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
 * conjunto cambie en absoluto.
 *
 * ## ESTE FICHERO DECÍA QUE CUBRÍA ESO Y NO LO CUBRÍA (corregido el 20 sep 2026)
 *
 * El párrafo de arriba seguía con *«lo que se guarda aquí es, para cada URI literal,
 * QUÉ acción la atiende»*, y eso **era falso**: la instantánea guarda
 * `VERBO uri => acción DECLARADA`, o sea lo que dice el fichero de rutas, y **eso no
 * se mueve al reordenar**. Poner el comodín delante de la literal deja `rutas.json`
 * byte a byte igual. La frase describía la protección que hacía falta, no la que
 * había.
 *
 * No es una hipótesis: **pasó dos veces en la misma familia con este test en verde**
 * —`…/campos` tapada por `{lote}`, y `…/pendientes` tapada por `{codigo}`— y las dos
 * se cazaron a mano, una por una, porque alguien se dio cuenta. La segunda contestaba
 * `getEstado` a quien pedía la bandeja del tesorero.
 *
 * Es la trampa del `CLAUDE.md` en su forma más cara: *un detector puede contar bien
 * un síntoma y no estar contando la causa*, y aquí encima **llevaba el nombre de la
 * causa escrito en el docblock**, que es lo que hizo que nadie fuera a mirar.
 *
 * Lo que sí lo cubre es `test_ninguna_ruta_literal_la_atiende_un_comodin`, que no
 * mira lo declarado: **le pregunta al router**.
 *
 * No hereda de CasoDeContrato: no toca la base de datos.
 */
class RutasTest extends TestCase
{
    public function test_cada_uri_la_atiende_la_misma_accion(): void
    {
        $resueltas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                $resueltas[$verbo.' '.$ruta->uri()] = $ruta->getActionName();
            }
        }

        ksort($resueltas);

        $ruta = __DIR__.'/Snapshots/rutas.json';

        if (! file_exists($ruta)) {
            file_put_contents(
                $ruta,
                json_encode($resueltas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );

            fwrite(STDERR, "\n  ↳ snapshot de rutas creado con ".count($resueltas)." entradas\n");

            $this->addToAssertionCount(1);

            return;
        }

        $esperadas = json_decode(file_get_contents($ruta), true);

        $faltan = array_diff_key($esperadas, $resueltas);
        $sobran = array_diff_key($resueltas, $esperadas);

        $this->assertSame([], $faltan, 'Desaparecieron rutas: '.implode(', ', array_keys($faltan)));
        $this->assertSame([], $sobran, 'Aparecieron rutas nuevas sin actualizar el snapshot: '.implode(', ', array_keys($sobran)));

        // Mismo conjunto de URIs: ahora, que cada una siga atendida por lo mismo.
        $cambiadas = [];

        foreach ($esperadas as $uri => $accion) {
            if (($resueltas[$uri] ?? null) !== $accion) {
                $cambiadas[$uri] = $accion.' → '.($resueltas[$uri] ?? 'nada');
            }
        }

        $this->assertSame([], $cambiadas, 'Rutas que ahora las atiende otra acción');
    }

    /**
     * **Cada ruta literal la tiene que atender SU acción, no un comodín de delante.**
     *
     * Laravel casa por orden de registro, así que `GET cosas/{id}` declarado antes que
     * `GET cosas/mias` se traga la segunda: la petición entra por el comodín con
     * `id="mias"` y contesta lo que conteste ese método — casi siempre un 404 que no
     * dice por qué, y en el peor caso **la respuesta de otra cosa con un 200**.
     *
     * ## POR QUÉ NO SIRVE MIRAR EL FICHERO DE RUTAS, QUE ES LO QUE SE HACÍA
     *
     * Este fallo **no cambia el conjunto de rutas ni la acción declarada de ninguna**:
     * las dos siguen ahí, con su verbo, su URI y su controlador. Sólo cambia **cuál
     * gana**, que es una propiedad del router y no del fichero. Por eso aquí no se lee
     * lo declarado: se construye la petición y **se le pregunta a `getRoutes()->match()`
     * quién la atiende**, que es literalmente lo que hará el servidor.
     *
     * ## LO QUE HACE QUE ESTO VALGA LA PENA ES QUE NO HAY QUE ACORDARSE
     *
     * Las dos veces que pasó en `informes/formularios-inscripcion` y en
     * `colillas-inscripcion` se arreglaron **a mano y por separado**, cada una cuando
     * alguien la vio. Un candado por caso protege el caso; éste recorre el router
     * entero, así que protege **las 6xx de hoy y las que se escriban mañana** sin que
     * nadie lo toque.
     *
     * Y `route:list` tampoco lo delata, que es lo que lo hace tan difícil de ver: lista
     * las dos rutas tan tranquilo, porque las dos existen.
     */
    public function test_ninguna_ruta_literal_la_atiende_un_comodin(): void
    {
        $tapadas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            // Sólo las literales: una ruta con comodín no puede estar «tapada», es
            // ella la que tapa.
            if (! str_starts_with($ruta->uri(), 'api/') || str_contains($ruta->uri(), '{')) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $clave = $verbo.' '.$ruta->uri();

                try {
                    $casada = Route::getRoutes()->match(Request::create('/'.$ruta->uri(), $verbo));
                } catch (\Throwable $e) {
                    // Que una ruta declarada no case con su propia URI es peor todavía
                    // que estar tapada, y se cuenta aquí para no necesitar dos tests.
                    $tapadas[] = $clave.'   no la atiende NADIE ('.$e->getMessage().')';

                    continue;
                }

                if ($casada->getActionName() !== $ruta->getActionName()) {
                    $tapadas[] = $clave.'   la atiende `'.$casada->uri().'` ('
                        .class_basename($casada->getActionName()).') en vez de la suya ('
                        .class_basename($ruta->getActionName()).')';
                }
            }
        }

        sort($tapadas);

        $this->assertSame([], $tapadas,
            "Estas rutas literales las atiende un comodín registrado ANTES que ellas:\n  "
            .implode("\n  ", $tapadas)
            ."\n\nSe arregla registrando la literal por delante del comodín, en el mismo "
            .'fichero de `routes/api/`. No se arregla con una excepción: la ruta tapada '
            .'no funciona, y `route:list` no lo dice porque las dos existen.');
    }

    public function test_no_hay_rutas_duplicadas(): void
    {
        $vistas = [];
        $duplicadas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                $clave = $verbo.' '.$ruta->uri();

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
