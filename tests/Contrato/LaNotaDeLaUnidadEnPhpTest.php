<?php

namespace Tests\Contrato;

use App\Models\Unidad;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Contrato\Concerns\LaPlanillaDelLienzo;

/**
 * **La nota de un criterio, calculada en PHP y no dentro de la consulta** (22 sep 2026).
 *
 * `Unidad::deAsignaturaCalculada` traía las unidades con un `SUM` sobre dos `LEFT JOIN` y
 * un `GROUP BY`; ahora trae las subunidades y suma en PHP. Encargo de Joseth, con su
 * motivo: *«lo hice en SQL porque creí que era más rápido para la página, además sólo se
 * calculaba por porcentaje; hoy se podría calcular por promedio a petición de cada
 * colegio»*.
 *
 * **Que el traslado no movió ningún número está medido y no supuesto**: se compararon las
 * dos fórmulas sobre **289.963 pares (unidad, alumno)** de la copia de desarrollo, con
 * **0 discrepancias**. Este fichero es lo que queda de esa medición cuando la copia ya no
 * esté: fija los números que la comparación recorrió en bloque, y sobre todo los **cuatro
 * casos que el SQL resolvía solo y ahora hay que escribir a mano** —el redondeo, el
 * `NULL`, la rama del desempeño y el hueco del `LEFT JOIN` de la escala—, que son
 * exactamente por donde una traducción se rompe.
 *
 * El lienzo: unidad 1 al 70 % con 48 al 30 %, 47 al 20 % y dos casillas al 25 % **sin
 * calificar**; unidad 2 al 30 % y sin indicadores.
 *
 *     nota_unidad = (48×30 + 47×20) ÷ (30 + 20) = 2.380 ÷ 50 = 47,6 → ROUND = 48
 */
class LaNotaDeLaUnidadEnPhpTest extends CasoDeContrato
{
    use LaPlanillaDelLienzo;

    /** Lo que decide «Debilidad» en la rama de `Boletines2Controller`. */
    private const MINIMA = 30;

    #[Test]
    public function la_nota_del_criterio_sale_sobre_lo_evaluado_y_redondeada(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $unidades = Unidad::deAsignaturaCalculada(
            $ctx['alumno'], $ctx['asignatura'], $ctx['periodo'], 'sin_desempenio', $ctx['year']
        );

        $this->assertCount(2, $unidades, 'El traslado a PHP cambió qué unidades salen.');

        // **Cadena y no número**, que es lo que devolvía `ROUND()` por PDO: de esta consulta
        // cuelgan cuatro informes y los cuatro clientes, y un refactor que «de paso» arregla
        // el tipo mueve el contrato sin que nadie lo haya pedido.
        $this->assertSame('48', $unidades[0]->nota_unidad,
            'La nota del criterio salió '.var_export($unidades[0]->nota_unidad, true).'. Con 24 '
            .'las dos casillas sin calificar vuelven a pesar como ceros; con 47.6 se perdió el '
            .'ROUND; con 48 en vez de "48" se movió el tipo que leen los cuatro clientes.');

        // La unidad sin una sola subunidad no es un 0: es que no hay nada que valorar. El
        // `SUM` de la consulta daba `NULL` y aquí lo da el `count() === 0`.
        $this->assertNull($unidades[1]->nota_unidad,
            'La unidad sin indicadores dejó de ser NULL. Un 0 ahí dice «sacó cero».');
    }

    #[Test]
    public function en_modo_promedio_es_la_media_de_lo_calificado(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        DB::table('years')->where('id', $ctx['year'])
            ->update(['reparto_subunidades' => RepartoDeLaNota::PROMEDIO]);

        $unidades = Unidad::deAsignaturaCalculada(
            $ctx['alumno'], $ctx['asignatura'], $ctx['periodo'], 'sin_desempenio', $ctx['year']
        );

        // **Es el caso que trajo el encargo.** En SQL, repartir a partes iguales obligaba a
        // contar las subunidades vivas con una subconsulta correlacionada por fila; aquí es
        // `count()`. (48 + 47) ÷ 2 = 47,5, y `round()` lo sube a 48.
        $this->assertSame('48', $unidades[0]->nota_unidad,
            'En promedio salió '.var_export($unidades[0]->nota_unidad, true).': con 24 el '
            .'divisor volvió a ser las cuatro casillas que existen en vez de las dos puestas.');
    }

    #[Test]
    public function la_rama_del_desempenio_dice_una_de_las_dos_palabras_y_nada_si_no_hay_notas(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $unidades = Unidad::deAsignaturaCalculada(
            $ctx['alumno'], $ctx['asignatura'], $ctx['periodo'], 'fortaleza_debilidad',
            $ctx['year'], self::MINIMA
        );

        $this->assertSame('Fortaleza', $unidades[0]->desempenio,
            'Con 48 sobre una mínima de 30 esto es una Fortaleza. Si dice Debilidad, la nota '
            .'del criterio volvió a cargar con lo que nadie ha calificado — que es el caso '
            .'que esta rama imprime en la cara del alumno.');

        // `IF(NULL < x, ...)` es `NULL` en SQL, no `"Fortaleza"`. Una unidad sin una sola
        // casilla calificada no es ni lo uno ni lo otro, y traducir eso a un `if` de PHP es
        // justo donde se cuela un «Debilidad» que nadie puso.
        $this->assertNull($unidades[1]->desempenio,
            'La unidad sin notas se llevó una palabra. Sin nota no hay desempeño.');

        // El orden de las claves es contrato: `desempenio` va entre `porcentaje_unidad` y
        // `asignatura_id`, como en el `SELECT` que había.
        $this->assertSame(
            ['unidad_id', 'definicion_unidad', 'porcentaje_unidad', 'desempenio',
                'asignatura_id', 'orden_unidad', 'periodo_id', 'nota_unidad'],
            array_keys((array) $unidades[0]),
            'Cambió el orden o el juego de claves de la unidad.');
    }

    #[Test]
    public function la_escala_se_pega_detras_y_sin_banda_las_claves_siguen_ahi(): void
    {
        $ctx = $this->laPlanillaDelLienzo();

        $unidades = Unidad::deAsignaturaCalculada(
            $ctx['alumno'], $ctx['asignatura'], $ctx['periodo'], 'con_desempenio', $ctx['year']
        );

        $claves = array_keys((array) $unidades[0]);

        // Era un `LEFT JOIN` con `SELECT *`: las columnas de la escala se pegan **detrás** de
        // las de la unidad. Se comprueban por el esquema y no por una lista escrita aquí,
        // que es lo que se quedaría corto el día que la tabla gane una columna.
        foreach (['id', 'valoracion', 'porc_inicial', 'porc_final', 'perdido'] as $columna) {
            $this->assertContains($columna, $claves,
                'La columna `'.$columna.'` de la escala dejó de viajar con la unidad.');
        }

        // **La mitad del `LEFT JOIN` que se olvida al traducirlo**: sin banda que case, las
        // claves seguían ahí en `null`. Un cliente que pregunte por `valoracion` no encuentra
        // lo mismo si la clave falta que si está vacía.
        $sinBanda = $unidades[1];

        $this->assertNull($sinBanda->nota_unidad);
        $this->assertArrayHasKey('valoracion', (array) $sinBanda,
            'Sin banda, la clave de la escala desapareció en vez de venir en null.');
        $this->assertNull($sinBanda->valoracion);
    }
}
