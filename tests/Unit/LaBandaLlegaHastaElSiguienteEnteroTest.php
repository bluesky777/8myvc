<?php

namespace Tests\Unit;

use App\Models\EscalaDeValoracion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Una nota con decimales cae en una banda: la de la nota IMPRESA.
 *
 * > ⚠️ **24 sep 2026 — la mitad de este docblock quedó en historia.** Por decisión
 * > de producto la banda se busca sobre **la nota redondeada**, la que ve el
 * > usuario (`App\Support\NotaImpresa`): 45,5 se imprime 46 y es SUPERIOR; 45,4 se
 * > imprime 45 y es ALTO. Lo que sigue explica por qué el 13 sep se decidió lo
 * > contrario («redondear sube de banda sin que nadie lo haya decidido»): ahora sí
 * > lo decidió alguien. Lo que se conserva es que los trece sitios usan **la misma
 * > regla**, y que el SQL también redondea (`ROUND(x, 0)`, sobre `DECIMAL`).
 *
 * ## El agujero que cierra, que estaba VIVO y no es de laboratorio
 *
 * `2026_08_30_200000_notas_finales_en_decimal` volvió `notas_finales.nota` un
 * `decimal(7,4)`. Las bandas de `escalas_de_valoracion` siguen siendo `int`, y en
 * un colegio real son contiguas por enteros:
 *
 *     BAJO 0-29   ·   BÁSICO 30-39   ·   ALTO 40-45   ·   SUPERIOR 46-50
 *
 * Con `porc_final >= nota`, **un 45,5 no casa con ninguna**: ALTO topa en 45 y
 * SUPERIOR empieza en 46. Y los trece sitios que buscan la banda no fallaban
 * igual, que es lo que lo hizo durar:
 *
 * - los **ocho en SQL** son un `LEFT JOIN`, así que devolvían `desempenio` a
 *   `NULL` y **el boletín salía sin nivel, en silencio**;
 * - los **cinco en PHP** hacían `round($nota)`, así que ese 45,5 subía a 46 y se
 *   imprimía **SUPERIOR** — donde el colegio había escrito que ALTO llega a 45.
 *
 * O sea que **el mismo alumno tenía dos niveles distintos según qué informe se
 * imprimiera**, y ninguno de los dos era el que el colegio escribió. Medido el
 * 13 sep 2026 sobre `simonbolivar`: de 127.891 definitivas, **13 no casaban con
 * ninguna banda y 4 eran exactamente por el decimal** (45,5 · 45,005 · 45,05 ·
 * 39,3 — las cuatro en una frontera). Con la regla de abajo quedan **9**, y esas
 * nueve son notas por encima del techo de la escala, que es otro asunto.
 *
 * ## La regla, y por qué ésta y no las otras dos
 *
 *     porc_inicial <= nota  AND  nota < porc_final + 1
 *
 * **No se tocó ningún dato y no asciende a nadie**: 45,5 es ALTO, que es lo que
 * el colegio quiso decir al escribir «ALTO, de 40 a 45». Las dos alternativas se
 * descartaron con su motivo:
 *
 * - **pasar las columnas a `decimal`** no arregla nada por sí solo: con las bandas
 *   en 0-29/30-39/40-45/46-50, un 45,5 seguiría sin casar. Sólo permite que el
 *   colegio *escriba* fronteras con decimales, que es otra cosa;
 * - **redondear antes de buscar** —lo que hacían los cinco de PHP— cierra el hueco
 *   y **sube de banda**: 45,5 pasaría a SUPERIOR. Cambia lo impreso en un boletín
 *   sin que nadie lo haya decidido.
 *
 * ## Por qué este test es de `Unit` y qué NO cubre
 *
 * `EscalaDeValoracion::valoracion()` es una función pura sobre una lista de
 * escalas, así que no hace falta base. **Cubre los cinco sitios de PHP**, porque
 * los cuatro controladores llevan una copia literal de este mismo `if`.
 *
 * **No cubre los ocho de SQL**, que son cadenas dentro de consultas y sólo se
 * comprueban ejecutándolas. Eso queda medido contra la base de desarrollo —13
 * huérfanas pasan a 9— y anotado en `docs/migracion/35-...` §1.3.
 *
 * ## El control, y da 2 de 11 — no 4, y el porqué es preciso
 *
 * Con el código de antes (`porc_final >= nota` más el `round()`) este fichero se ve rojo en
 * **dos** casos: 45,5 y 45,999, que son los que el redondeo subía a 46 → SUPERIOR. Los otros dos
 * decimales medidos —45,005 y 39,3— **caían bien por accidente**, porque redondean hacia abajo.
 *
 * O sea que **los dos caminos fallaban en subconjuntos distintos**: el de PHP sólo en los que
 * redondean hacia arriba, y el de SQL en los cuatro, porque no redondeaba nada. Un test escrito
 * mirando sólo el camino de PHP habría dado el problema por dos casos raros en vez de por lo que
 * era.
 */
class LaBandaLlegaHastaElSiguienteEnteroTest extends TestCase
{
    /** Las cuatro bandas de un colegio de verdad, contiguas por enteros. */
    private function escalas(): array
    {
        return [
            (object) ['desempenio' => 'BAJO', 'porc_inicial' => 0, 'porc_final' => 29],
            (object) ['desempenio' => 'BÁSICO', 'porc_inicial' => 30, 'porc_final' => 39],
            (object) ['desempenio' => 'ALTO', 'porc_inicial' => 40, 'porc_final' => 45],
            (object) ['desempenio' => 'SUPERIOR', 'porc_inicial' => 46, 'porc_final' => 50],
        ];
    }

    public static function notas(): array
    {
        return [
            // Los cuatro decimales que estaban huérfanos en producción: se imprimen
            // redondeados y llevan la banda de lo impreso.
            'el 45,5 medido, que se imprime 46' => [45.5, 'SUPERIOR'],
            'el 45,005' => [45.005, 'ALTO'],
            'el 45,05' => [45.05, 'ALTO'],
            'el 39,3' => [39.3, 'BÁSICO'],

            // Los enteros no se mueven: es la mitad que dice que esto no cambia
            // lo que se imprime en los casos sanos, que son casi todos.
            'el borde de abajo' => [40, 'ALTO'],
            'el borde de arriba' => [45, 'ALTO'],
            'el primero de la siguiente' => [46, 'SUPERIOR'],
            'el cero' => [0, 'BAJO'],
            'el techo' => [50, 'SUPERIOR'],

            // La frontera del redondeo, con los números del ejemplo de producto
            // (59,9 / 59,4 contra 60) llevados a esta escala de 0 a 50.
            'justo antes del siguiente, que se imprime 46' => [45.999, 'SUPERIOR'],
            'el 45,4 se imprime 45' => [45.4, 'ALTO'],
            'el 29,9 se imprime 30' => [29.9, 'BÁSICO'],
            'el 29,4 se imprime 29' => [29.4, 'BAJO'],
            'la cadena decimal que devuelve PDO' => ['29.9000', 'BÁSICO'],
        ];
    }

    #[DataProvider('notas')]
    public function test_la_nota_cae_en_la_banda_de_lo_que_se_imprime($nota, string $esperado): void
    {
        $escala = EscalaDeValoracion::valoracion($nota, $this->escalas());

        $this->assertNotNull($escala,
            "La nota {$nota} no cayó en ninguna banda. Con `porc_final >= nota` esto le pasaba a "
            .'todo decimal en una frontera, y el boletín salía sin nivel.');

        $this->assertSame($esperado, $escala->desempenio,
            "La nota {$nota} cayó en {$escala->desempenio} y debía caer en {$esperado}. "
            .'La banda es la de la nota redondeada, la impresa (`NotaImpresa`, 24 sep 2026).');
    }

    /**
     * Por encima del techo no hay banda, y eso SÍ tiene que seguir sin casar.
     *
     * **Y el fallback no es `null`**: el modelo devuelve `(object)['desempenio' => '']`, que es
     * lo que este test fija. Se escribió esperando `null` y se vio rojo por eso — el código tenía
     * razón y la expectativa no.
     *
     * > ⚠️ **Las cinco copias NO devuelven lo mismo cuando no casa ninguna banda**, y esto no se
     * > arregla aquí porque es comportamiento vivo: el modelo devuelve ese objeto con
     * > `desempenio` vacío, y las cuatro de `PromovidosController`,
     * > `Informes\BolfinalesController`, `Informes\BolfinalesPreescolarController` y
     * > `Informes\CertificadosPersonaController` devuelven **`[]`**, un array. Quien haga
     * > `->desempenio` sobre el array se come un aviso de PHP en vez de una cadena vacía. Es la
     * > misma duplicación que hizo falta tocar en cinco sitios para cambiar una regla, vista por
     * > su otro lado.
     */
    public function test_por_encima_del_techo_sigue_sin_banda(): void
    {
        $escala = EscalaDeValoracion::valoracion(51, $this->escalas());

        $this->assertSame('', $escala->desempenio,
            'Un 51 sobre una escala que acaba en 50 no es un hueco de frontera: es una nota fuera '
            .'de la escala, y taparlo aquí escondería un dato malo. Son 9 de las 13 medidas.');
    }
}
