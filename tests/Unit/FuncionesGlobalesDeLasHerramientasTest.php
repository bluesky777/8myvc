<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Dos ficheros de `tools/` no pueden declarar la misma función global.
 *
 * Los scripts de `tools/` no tienen `namespace`: cada uno corre en su propio
 * proceso, así que **en ejecución dos funciones con el mismo nombre no se ven
 * nunca**. Pero larastan analiza la carpeta entera como un solo proyecto, y ahí
 * sí se ven: resuelve la llamada de un fichero **contra la función del otro**.
 *
 * ## Qué pasó el 4 sep 2026, que es por lo que esto existe
 *
 * El `--control` de `columnas-en-los-modelos.php` se escribió con los nombres
 * naturales, `casosDeControl()` y `control()`, que son los que ya usaba
 * `independientes-sin-estructura.php`. Larastan cantó:
 *
 *     tools/columnas-en-los-modelos.php:400
 *     Trying to invoke array<string, mixed> but it might not be a callable.
 *
 * Ese `array<string, mixed>` **es la firma del OTRO `casosDeControl`**. La
 * anotación del fichero que fallaba era correcta y no se estaba usando.
 *
 * ## Y la mitad que muerde: larastan NO es el detector de esto
 *
 * Lo fue por casualidad, porque los dos `casosDeControl` devolvían tipos
 * distintos. Con `control()` —los dos devuelven `int`— **no salió ningún error**:
 * el análisis pasa en verde estando mal resuelto. O sea que la colisión que no
 * cambia de tipo no se delata sola, y un control que se analiza contra otro
 * fichero es exactamente un control que no sabe fallar.
 *
 * ## Por qué este test dice su población, y por qué trae un control negativo
 *
 * La respuesta sana es una lista vacía, y una lista vacía no distingue *«miré 40
 * ficheros y ninguno choca»* de *«no miré nada»* — que es la regla de `CLAUDE.md`
 * sobre los detectores. Dos defensas, y las dos hacen falta:
 *
 * 1. **Dice cuántos ficheros miró y cuántas funciones contó**, y se planta si
 *    alguna de las dos cifras es cero. El día que alguien meta un `namespace` en
 *    `tools/` —o cambie el estilo de declaración— el `grep` dejaría de encontrar
 *    nada y el cero se leería como «no hay colisiones».
 * 2. **El control negativo demuestra que sabe ponerse rojo**, sobre ficheros
 *    sintéticos que sí chocan. Sin él, «cero colisiones» y «detector roto» se
 *    escriben igual.
 *
 * Los ficheros con `namespace` se cuentan aparte y su nombre se cualifica: ahí la
 * colisión no existe, y contarla sería un rojo falso que acabaría con alguien
 * apagando el test.
 */
class FuncionesGlobalesDeLasHerramientasTest extends TestCase
{
    /**
     * Las funciones de nivel superior de cada fichero, por nombre.
     *
     * Se mira la declaración a principio de línea, que es como están escritas
     * todas: los métodos de una clase van indentados. Es el mismo criterio que el
     * `grep` de una línea con el que se encontró esto, puesto donde se ejerce.
     *
     * @param  list<string>  $ficheros
     * @return array{funciones: array<string, list<string>>, conNamespace: int}
     */
    private function funcionesPorNombre(array $ficheros): array
    {
        $funciones = [];
        $conNamespace = 0;

        foreach ($ficheros as $fichero) {
            $fuente = (string) file_get_contents($fichero);
            $prefijo = '';

            if (preg_match('/^namespace\s+([^;]+);/m', $fuente, $ns) === 1) {
                $conNamespace++;
                $prefijo = trim($ns[1]).'\\';
            }

            preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)/m', $fuente, $m);

            foreach ($m[1] as $nombre) {
                $funciones[$prefijo.$nombre][] = basename($fichero);
            }
        }

        return ['funciones' => $funciones, 'conNamespace' => $conNamespace];
    }

    public function test_ningun_nombre_de_funcion_esta_declarado_en_dos_herramientas(): void
    {
        $ficheros = glob(dirname(__DIR__, 2).'/tools/*.php');
        $this->assertNotFalse($ficheros, 'No se pudo listar tools/');

        $medida = $this->funcionesPorNombre($ficheros);
        $poblacion = count($ficheros).' ficheros de tools/, '
            .count($medida['funciones']).' nombres de función distintos, '
            .$medida['conNamespace'].' ficheros con namespace';

        // Las dos cifras antes del veredicto: un cero aquí significaría que el
        // detector dejó de encontrar declaraciones, no que no haya colisiones.
        $this->assertNotSame(0, count($ficheros), "No se miró ningún fichero — {$poblacion}");
        $this->assertNotSame(0, count($medida['funciones']),
            'No se contó ninguna función de nivel superior en tools/. El detector busca '
            ."`^function nombre` a principio de línea; si cambió el estilo, cambia esto — {$poblacion}");

        $chocan = [];
        foreach ($medida['funciones'] as $nombre => $donde) {
            if (count($donde) > 1) {
                $chocan[] = $nombre.'(): '.implode(', ', $donde);
            }
        }

        $this->assertSame([], $chocan,
            "Dos herramientas declaran la misma función global. En ejecución no pasa nada —un\n"
            ."proceso por script— pero larastan analiza tools/ como un proyecto y resuelve la\n"
            ."llamada de una CONTRA LA FUNCIÓN DE LA OTRA, con su firma y sus tipos.\n\n"
            ."Y no cuentes con que larastan lo cace: sólo se queja cuando los tipos discrepan.\n"
            ."Dos funciones homónimas que devuelvan lo mismo pasan en verde mal resueltas.\n\n"
            ."Se arregla renombrando, no anotando. Población: {$poblacion}");
    }

    /**
     * El control negativo: con ficheros que sí chocan, tiene que verlo.
     *
     * Va sobre ficheros sintéticos y no sobre un commit anterior a propósito: un
     * control que llama a `git` no se puede ejercer desde un worktree, que es
     * donde corre la suite de una noche en paralelo — está medido y escrito en
     * `AutopruebasDeLasHerramientasTest`.
     */
    public function test_el_detector_sabe_ponerse_rojo(): void
    {
        $dir = sys_get_temp_dir().'/colisiones-'.getmypid();
        @mkdir($dir, 0o777, true);

        $a = $dir.'/una.php';
        $b = $dir.'/otra.php';
        $c = $dir.'/con-namespace.php';

        file_put_contents($a, "<?php\nfunction control(): int { return 0; }\n");
        file_put_contents($b, "<?php\nfunction control(): int { return 1; }\nfunction suya(): int { return 2; }\n");
        // El tercero declara el MISMO nombre pero bajo namespace: no choca, y
        // contarlo sería el rojo falso que acaba con alguien apagando el test.
        file_put_contents($c, "<?php\nnamespace Otro;\nfunction control(): int { return 3; }\n");

        $medida = $this->funcionesPorNombre([$a, $b, $c]);

        @unlink($a);
        @unlink($b);
        @unlink($c);
        @rmdir($dir);

        $this->assertSame(['una.php', 'otra.php'], $medida['funciones']['control'] ?? [],
            'El detector NO ve una colisión que existe, así que su lista vacía sobre tools/ '
            .'no significa nada.');

        $this->assertSame(['otra.php'], $medida['funciones']['suya'] ?? [],
            'El detector marca como colisión una función que sólo está declarada una vez.');

        $this->assertSame(['con-namespace.php'], $medida['funciones']['Otro\\control'] ?? [],
            'Una función bajo namespace tiene que contarse cualificada: ahí la colisión no existe.');

        $this->assertSame(1, $medida['conNamespace'],
            'No se está contando cuántos ficheros llevan namespace, que es lo que explicaría '
            .'un día un cero que no es un cero.');
    }
}
