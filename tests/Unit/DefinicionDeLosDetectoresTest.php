<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * **Las definiciones que los documentos citan, y que envejecen sin que nadie se
 * entere.** §92.0.
 *
 * Un detector tiene dos clases de límite: los de implementación —una regex que
 * recorta mal— y los de **definición**: qué tablas mira, qué carpeta recorre. Los
 * primeros son fallos y se arreglan. Los segundos **son decisiones**, y por eso
 * las secciones del 05 los citan textualmente para acotar sus respuestas:
 *
 * > *«la respuesta de este lote es correcta sobre sus trece rutas y sobre las tres
 * > tablas que el detector mira»* — §92.0
 *
 * **Y una cita es una copia.** El 23 de agosto de 2026, la §92.0 se escribió
 * diciendo que `TABLAS` tenía tres tablas; unas horas después, el apéndice del
 * lote O **le añadió la cuarta**, y la §92.0 quedó afirmando en presente algo que
 * ya no era cierto. Las dos las escribió la misma sesión, la misma noche.
 *
 * > Una **anotación** sin condición de caducidad espera a una persona. Una
 * > **conclusión** sin condición de caducidad **se sigue citando como si fuera
 * > cierta**, que es peor: nadie la está esperando, así que nadie la revisa.
 *
 * Este caso es esa condición. No comprueba que la definición sea la correcta
 * —eso es una decisión— sino que **si cambia, alguien tenga que ir a mirar las
 * secciones que la citan**.
 *
 * Vive en `tests/Unit` a propósito: no necesita base de datos, y así corre también
 * en las tandas que no la tienen.
 */
class DefinicionDeLosDetectoresTest extends TestCase
{
    /**
     * Lo que cada detector mira, y **qué secciones lo citan**.
     *
     * El valor no es una nota: es la lista de sitios que hay que releer el día que
     * el caso caiga. Sin ella, el test diría «cambió» y no diría dónde duele.
     */
    private const DEFINICIONES = [
        'tools/escrituras-en-las-notas.py' => [
            'patron' => "/TABLAS = \((.+?)\)/",
            'esperado' => "'notas', 'notas_finales', 'recuperacion_final', 'nota_comportamiento'",
            'citada_en' => 'c.md §92.0 (el alcance de la respuesta del lote C) y o.md, apéndice del detector',
        ],

        // Qué ficheros de cada cliente se leen. El §106.1 afirma «ninguna de las
        // 49 aparece» **sobre estas extensiones**, así que ampliarla o
        // estrecharla cambia el alcance de esa frase.
        'tools/interruptores-que-nadie-lee.py' => [
            'patron' => "/EXTENSIONES = \((.+?)\)/",
            'esperado' => "'.js', '.mjs', '.ts', '.html', '.dart', '.vue'",
            'citada_en' => 'g.md §106.1 (contra qué se midió el 49) y la cabecera del propio script',
        ],

        // El umbral por el que una familia entra o no en el candado. El §151
        // cuenta 18 familias y 23 rutas **con este umbral**: cambiarlo cambia
        // los dos números y el sentido del snapshot que los guarda.
        'tests/Contrato/AutorizacionTest.php' => [
            'patron' => '/if \((\$conGuard < 2 \|\| \$sinGuard > max\(2, intdiv\(count\(\$rutas\), 4\)\))\) \{/',
            'esperado' => '$conGuard < 2 || $sinGuard > max(2, intdiv(count($rutas), 4))',
            'citada_en' => 'q.md §151 (las 18 familias que nunca entran) y j.md §114 (las que se salen)',
        ],
    ];

    /**
     * **Y una que NO se fija a propósito**, porque fijarla sería estorbar.
     *
     * `identificadores-del-cuerpo.py` tiene su señal de propiedad —la raíz `exig`—
     * citada en g.md §107.1 y en c.md §91, y **está siendo arreglada por el lote
     * H**: se traga `ColumnaSegura::exigir`, que valida un nombre de columna y no
     * comprueba propiedad de nada. Ponerla aquí haría que el arreglo de otra
     * sesión rompiera este test, y un test que se rompe cuando alguien hace bien
     * su trabajo se acaba borrando.
     *
     * Entra el día que H cierre, y entonces con el valor nuevo. Se deja escrito
     * aquí para que no parezca un olvido: **una definición que alguien está
     * cambiando no necesita un centinela, necesita que le dé tiempo a cambiar.**
     */
    private const NO_SE_FIJA_TODAVIA = [
        'tools/identificadores-del-cuerpo.py' => 'la regex PROPIEDAD; la está arreglando el lote H',
    ];

    public function test_las_definiciones_citadas_por_el_05_no_han_cambiado(): void
    {
        foreach (self::DEFINICIONES as $herramienta => $d) {
            $ruta = dirname(__DIR__, 2).'/'.$herramienta;

            $this->assertFileExists($ruta, "La herramienta {$herramienta} cambió de sitio o de nombre.");

            $encontrado = preg_match($d['patron'], file_get_contents($ruta), $m) ? trim($m[1]) : null;

            $this->assertSame($d['esperado'], $encontrado,
                "Cambió la DEFINICIÓN de {$herramienta}, que no es un detalle de implementación:\n".
                "es lo que decide qué puede encontrar y qué no, y hay secciones del 05 que la citan\n".
                "para acotar su respuesta —{$d['citada_en']}—.\n\n".
                "Si el cambio es bueno (mirar más), esas secciones se quedaron cortas y hay que\n".
                "volver a correr lo que afirmaban. Si es malo (mirar menos), hay respuestas que\n".
                "pasaron a ser más estrechas de lo que dicen.\n\n".
                'Actualiza el valor aquí después de releerlas, no antes.');
        }
    }
}
