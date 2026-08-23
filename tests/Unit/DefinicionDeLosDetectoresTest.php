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
 * ## La entrada que faltaba, y cómo entró
 *
 * Aquí hubo una constante `NO_SE_FIJA_TODAVIA` con la señal de propiedad de
 * `identificadores-del-cuerpo.py` dentro y este motivo al lado: *«una definición
 * que alguien está cambiando no necesita un centinela, necesita que le dé tiempo
 * a cambiar»* — el lote H la estaba arreglando, y fijarla habría hecho que el
 * arreglo de otra sesión rompiera este test.
 *
 * **H cerró, y la nota se convirtió en dos entradas.** Es la única de las
 * condiciones de caducidad de esta noche que **se ha cumplido dentro de la propia
 * noche**, y por eso vale la pena que quede escrito: una anotación con caducidad
 * no se queda para siempre, **se gasta**.
 *
 * Y se gastó dejando algo que la nota no podía dejar: la señal resultó ser
 * **dos** —`PROPIEDAD` y `NO_ES_PROPIEDAD`, lo que cuenta y lo que se resta—, así
 * que un centinela puesto antes de tiempo habría fijado sólo la mitad.
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

        // La señal de propiedad, y su resta. **Entró el día que el lote H cerró**,
        // que es lo que decía la nota que había aquí: «una definición que alguien
        // está cambiando no necesita un centinela, necesita que le dé tiempo a
        // cambiar». H le dio tiempo, arregló lo que citaban g.md §107.1 y c.md §91
        // —la raíz `exig` se tragaba `ColumnaSegura::exigir`, que valida un nombre
        // de columna y no comprueba de quién es la fila— y la nota se convierte en
        // esto: dos entradas que se comprueban.
        //
        // Van las **dos**, y ése es el punto: la señal ya no es una regex, son dos
        // —lo que cuenta y lo que se resta—, así que una definición que se citara
        // sólo por la primera volvería a quedarse corta.
        'tools/identificadores-del-cuerpo.py (PROPIEDAD)' => [
            'fichero' => 'tools/identificadores-del-cuerpo.py',
            'patron' => "/PROPIEDAD = re\.compile\(r'(.+?)'\s*$/m",
            'esperado' => 'exig|pedidoPropio|is_superuser|esSuperusuario|',
            'citada_en' => 'g.md §107.1 y c.md §91 (las cinco rutas que se colaban por ColumnaSegura::exigir)',
        ],
        'tools/identificadores-del-cuerpo.py (NO_ES_PROPIEDAD)' => [
            'fichero' => 'tools/identificadores-del-cuerpo.py',
            'patron' => "/NO_ES_PROPIEDAD = re\.compile\(r'(.+?)'\)/",
            'esperado' => 'ColumnaSegura::exigir',
            'citada_en' => 'g.md §107.1 — es la resta que el lote H añadió para que esas cinco dejaran de colarse',
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

    public function test_las_definiciones_citadas_por_el_05_no_han_cambiado(): void
    {
        foreach (self::DEFINICIONES as $herramienta => $d) {
            // La clave puede llevar una etiqueta entre paréntesis —hay ficheros
            // con más de una definición citada—, así que el fichero va aparte.
            $ruta = dirname(__DIR__, 2).'/'.($d['fichero'] ?? $herramienta);

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
