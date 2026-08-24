<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * **El censo de interruptores del esquema, que es la mitad del 49 que sí se puede
 * guardar desde aquí.** §105.
 *
 * El [lote G](../../docs/migracion/noche-2026-08-23/g.md) contestó con tres
 * números: **157** columnas `tinyint(1)` en el esquema, **48** que el backend ni
 * nombra, **44** que aparecen y no deciden nada. Y con dos más —**49** y **53**—
 * que salen de cruzar eso con los clientes.
 *
 * **Los tres primeros dependen sólo de este repositorio. Los dos últimos, no.**
 * `49` y `53` se miden contra `myvc_front` (23 ramas), `myvc_front_2`,
 * `myvc_flutter` y un bundle construido, que no están aquí y que se mueven solos:
 * ningún test de este repo puede guardarlos, y decir que sí sería peor que no
 * tenerlos.
 *
 * > Un número que depende de repositorios que no son éste **no se guarda: se
 * > fecha.** Por eso el §106 dice contra qué corpus se midió y cuándo, y por eso
 * > este caso cubre sólo la parte que vive aquí.
 *
 * Lo que sí guarda es el censo del esquema, que es lo que hace que el 49 tenga
 * sentido: si mañana aparecen tres `tinyint(1)` nuevas y nadie lo nota, el 49 del
 * documento pasa a hablar de otra población sin que cambie ninguna palabra.
 *
 * Y es lo que le faltó a la [§72](../../docs/migracion/05-codigo-muerto-y-roto.md):
 * **no se equivocó en el criterio, se equivocó en el censo.**
 */
class CensoDeInterruptoresTest extends TestCase
{
    /**
     * Medido el 23 ago 2026 con `tools/interruptores-que-nadie-lee.py`, **después**
     * de quitarle los comentarios al barrido.
     *
     * Los números de la primera versión del §105 eran `48 / 44 / 65`: la
     * herramienta leía los ficheros enteros, así que un comentario contaba como
     * código. Escribir este centinela fue lo que lo destapó — no coincidía con la
     * herramienta, y el que estaba mal era ella.
     */
    private const CENSO = [
        'columnas tinyint(1) distintas' => 157,
        'ni se nombran' => 64,
        'no deciden nada' => 29,
        'alguien decide con ellas' => 64,
    ];

    /**
     * **Movido el 25 ago 2026 al fundir las cuatro ramas de la noche del 24, y el
     * centinela hizo exactamente lo que tenía que hacer: saltó.**
     *
     * Se movió **una sola columna, `matriculas.profes_editar_notas`**, de «ni se
     * nombran» a «no deciden nada». No la escribió nadie a mano: `9e` cambió tres
     * `SELECT m.*` sobre `matriculas` por la lista de columnas nombradas
     * (`c6acfe3`, la fase 1 del boletín independiente), y **nombrar no es leer** —
     * la columna viaja en el `SELECT` y sigue sin decidir nada en ningún sitio.
     *
     * **Ninguna de las dos ramas lo habría visto sola:** el censo lo mueve el
     * código de `9e` y el guardián vino de `39`. Sólo salta cuando las dos están
     * en el mismo árbol, que es el argumento para correr la suite entera **al
     * fundir** y no sólo dentro de cada rama.
     *
     * ## Y por qué el 49 y el 53 del §105 NO se mueven con esto
     *
     * Los dos montones que cambiaron son **las dos mitades de lo mismo**: «el
     * backend no decide nada con ella». Su suma —**93**— es idéntica antes y
     * después, y **es esa suma, no el reparto, lo que el §105 cruza con los cuatro
     * clientes** para llegar al 49 y al 53. Así que aquí hay un cambio real que
     * este centinela debe registrar **y** dos números del documento que siguen
     * hablando de la misma población.
     *
     * Se deja el `assertSame` sobre los cuatro números **y no sobre la suma**, a
     * propósito: la suma sola habría dejado pasar esto en silencio, y el día que
     * una columna cruce de verdad a «alguien decide con ella» quiero verlo. El
     * precio es este comentario cada vez que se mueva el reparto; es más barato que
     * un guardián que no distingue.
     */
    private const SIN_LECTOR_EN_EL_BACKEND = 93;

    /** Las `tinyint(1)` del volcado, con las tablas donde están. Igual que la herramienta. */
    private function columnasBooleanas(): array
    {
        $volcado = file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-schema.sql');

        $tablas = [];
        $tabla = null;

        foreach (explode("\n", $volcado) as $linea) {
            if (preg_match('/^CREATE TABLE `([a-z0-9_]+)`/i', $linea, $m)) {
                $tabla = $m[1];
            } elseif (preg_match('/^\s*`([a-z0-9_]+)`\s+tinyint\(1\)/i', $linea, $m) && $tabla) {
                $tablas[$m[1]][$tabla] = true;
            }
        }

        return $tablas;
    }

    /** El código del backend, sin comentarios: la §72.5 otra vez. */
    private function codigo(): string
    {
        $texto = '';

        foreach (['app', 'routes', 'config', 'database/seeders'] as $carpeta) {
            $dir = dirname(__DIR__, 2).'/'.$carpeta;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                if ($f->isDir() || $f->getExtension() !== 'php') {
                    continue;
                }

                $texto .= implode('', array_map(
                    fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($t) ? $t[1] : $t),
                    token_get_all(file_get_contents($f->getPathname()))
                ))."\n";
            }
        }

        return $texto;
    }

    /**
     * **Los tres montones siguen teniendo el mismo tamaño.**
     *
     * Si cambian, no es un fallo: es que **la población de la que habla el §105 ya
     * no es la misma**, y hay que volver a correr la herramienta con los clientes
     * antes de seguir citando el 49 y el 53.
     */
    public function test_el_censo_del_esquema_no_ha_cambiado(): void
    {
        $columnas = $this->columnasBooleanas();
        $codigo = $this->codigo();

        $nunca = $mudas = $vivas = 0;

        foreach (array_keys($columnas) as $nombre) {
            $apariciones = preg_match_all('/\b'.preg_quote($nombre, '/').'\b/', $codigo);

            if ($apariciones === 0) {
                $nunca++;

                continue;
            }

            // La misma señal que la herramienta: una condición delante, en la
            // misma cadena. `on` entra porque aquí se filtra en los JOIN.
            preg_match('/\b(where|and|or|on|having|when|if|case)\b[^;]{0,120}\b'.preg_quote($nombre, '/').'\b/i', $codigo)
                ? $vivas++
                : $mudas++;
        }

        $este = [
            'columnas tinyint(1) distintas' => count($columnas),
            'ni se nombran' => $nunca,
            'no deciden nada' => $mudas,
            'alguien decide con ellas' => $vivas,
        ];

        // **La suma que el §105 cruza con los clientes, afirmada aparte.** Si un
        // día el reparto se mueve y esta suma no, el 49 y el 53 siguen hablando de
        // la misma población; si se mueve ésta, no. Son dos preguntas distintas y
        // por eso son dos aserciones y no una — el 25 ago la primera saltó y la
        // segunda no, y saberlo es lo que evitó volver a medir contra cuatro
        // repositorios que no están aquí.
        $this->assertSame(self::SIN_LECTOR_EN_EL_BACKEND, $nunca + $mudas,
            'Cambió cuántas columnas `tinyint(1)` no lee NADIE en el backend, que es la población '
            .'con la que el §105 llega al 49 y al 53. Esos dos hay que volver a medirlos contra los '
            .'cuatro clientes: ningún test de este repositorio puede hacerlo.');

        $this->assertSame(self::CENSO, $este,
            "Cambió el censo de interruptores del esquema.\n".
            "El §105 contesta con 49 y 53 columnas sin lector, y esos números salen de cruzar ESTE censo\n".
            "con los cuatro clientes. Si el censo se movió, aquellos dos hablan de otra población aunque\n".
            "no haya cambiado ni una palabra del documento.\n\n".
            "Vuelve a correr `tools/interruptores-que-nadie-lee.py --clientes …` con rutas ABSOLUTAS\n".
            'antes de actualizar nada aquí. Ver docs/migracion/noche-2026-08-23/g.md §105 y §106.');
    }
}
