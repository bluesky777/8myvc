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

    /*
     * ## 23 sep 2026 — tres cruzaron de «decide» a «no decide nada», y es MENTIRA
     *
     * `26 / 67` pasa a `29 / 64`, y la suma sin lector de **90 a 93**. Son
     * `can_see_results`, `votan_acudientes` y `votan_profes`, de `vt_votaciones`, y
     * **está comprobado cuáles son** —diff del censo entre `42c0f45^1` y `main`—, no
     * deducido. Las tres **siguen decidiendo**: el rediseño de votaciones (11 §8)
     * borró las consultas que las filtraban con `WHERE` y ahora se leen como PHP
     * —`$conConteo = (bool) $votacion->can_see_results || …` en
     * `VtResultadosController`, y el mapa `ESTAMENTO` de `VtVotacion` para las dos
     * `votan_*`—, que la señal de este censo (una palabra de condición delante) no ve.
     *
     * **O sea que la población del §105 NO cambió** aunque el número sí: no hay que
     * volver a medir el 49 y el 53 contra los clientes por estas tres. Lo que no se
     * midió es si alguna OTRA columna muda de las 93 es, igual que éstas, una que se
     * lee sin palabra de condición.
     */

    /*
     * ## 21 sep 2026 — `perdido` cruzó de «no decide nada» a «alguien decide con
     * ella», y esta vez el centinela lo cazó TARDE
     *
     * `27 / 66` pasa a `26 / 67`, y la suma sin lector de **91 a 90**. La columna es
     * `perdido` —`escalas_de_valoracion`—, y **está comprobado cuál es y no deducido**:
     * el censo se corrió sobre tres árboles (`3e16747~1`, `3e16747` y el de trabajo) y
     * el único nombre que se movió es ése.
     *
     * Quien le dio el lector es la fase 1 de «notas sin internet» (doc
     * [49](../../docs/migracion/49-la-planilla-sin-internet.md)):
     * `LaPlanillaQueSeDescarga` la trae en el `SELECT` de las bandas y
     * `HojaDeAsignatura:885` decide con ella —`if ($banda->perdido)`— para pintar la
     * banda reprobatoria en rojo **y además en negrita**, porque una planilla tiene que
     * poder leerse fotocopiada en blanco y negro y un color solo no sobrevive a eso.
     *
     * **Es el movimiento bueno**: una columna menos sin lector. Y aun así el centinela
     * tiene razón en ponerse rojo, porque el 49 y el 53 del §105 salen de cruzar esta
     * población con los cuatro clientes y ya no hablan de la misma.
     *
     * *Lo que sí es un fallo: esto estuvo rojo desde el commit `3e16747` porque la
     * suite entera no se corrió antes de commitear. La columna no se coló — se contó
     * tarde.*
     *
     * ## 13 sep 2026 — `por_defecto` cruzó de «no decide nada» a «alguien decide con
     * ella», y este centinela cazó el momento exacto
     *
     * `29 / 64` pasa a `28 / 65`. **Se movió una sola columna, y está comprobado cuál
     * es**: `por_defecto` —`unidades` y `subunidades` en el volcado—, que hasta hoy
     * la **escribían** los dos sembradores y no la **leía** nadie para decidir.
     *
     * Lo que la cruza es el candado de la **D14** que trae la Fase 3
     * (`DesempenosController::exigirElCandado`):
     *
     *     if ((int) $fila->por_defecto === 0) {
     *         return;
     *     }
     *
     * **Es el primer sitio del backend donde esa columna decide algo**, que es
     * justamente lo que la decisión 14 del doc 28 existía para construir. O sea que
     * este renglón no registra un accidente: registra que un interruptor dejó de ser
     * decorativo, que es lo que este caso vino a poder ver.
     *
     * > **Y el 49 y el 53 del §105 hay que volver a medirlos**, porque la suma
     * > `nunca + mudas` baja de **93** a **92** y ésa es la población que aquel
     * > apartado cruza con los cuatro clientes. **Ningún test de este repositorio
     * > puede hacerlo** —`myvc_front`, `myvc_front_2`, `myvc_flutter` y un bundle
     * > construido no están aquí—, así que se anota y se deja fechado, que es lo que
     * > manda el docblock de arriba.
     */

    /*
     * ## 17 sep 2026 — `por_defecto` volvió a cruzar, en la dirección contraria
     *
     * `28 / 65` vuelve a `29 / 64`, y la suma `nunca + mudas` vuelve de **92** a
     * **93**. **Se movió la misma columna del renglón de arriba y en sentido
     * inverso**: `por_defecto`, y por la razón exacta por la que había cruzado.
     *
     * Lo que la leía era `DesempenosController::exigirElCandado` —el candado de la
     * **D14**—, y la **D31** lo borró entero: si el colegio y el docente escriben
     * **las mismas filas físicas**, no hay nada que marcar como «del colegio».
     * Con el candado se fue el único `if` del backend que decidía con esa columna, y
     * `unidades.por_defecto` y `subunidades.por_defecto` vuelven a ser lo que eran:
     * columnas que los sembradores **escriben** y que nadie **lee** para decidir.
     *
     * **Este renglón no registra un accidente, registra que una decisión se
     * deshizo** — y es la prueba de que el centinela sirve en las dos direcciones,
     * que es lo que no se puede saber escribiéndolo una sola vez.
     *
     * > **Y el 49 y el 53 del §105 vuelven a quedar pendientes de remedir**, por lo
     * > mismo que decía el renglón del 13 sep y con la suma en el mismo sitio en el
     * > que estaba antes de aquél: **93**. Quien los mida contra los cuatro clientes
     * > está midiendo, otra vez, exactamente la población del 25 de agosto.
     */

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
     *
     * ## 19 sep 2026 — TERCER cruce de `por_defecto`, y esta vez nadie lo apuntó
     *
     * `29 / 64` vuelve a `28 / 65` y la suma vuelve de **93** a **92**. Es **la
     * misma columna por tercera vez**, y las tres con causa distinta y escrita:
     *
     * | | | quién la hace decidir |
     * |---|---|---|
     * | 13 sep | 29/64 → 28/65 | `DesempenosController::exigirElCandado` (D14) |
     * | 17 sep | 28/65 → 29/64 | la Fase 2 sustituye ese código — 21 rutas se quedan en 7 |
     * | **19 sep** | **29/64 → 28/65** | **`CandadoDeLaPlantilla:99`, `if (empty($fila->por_defecto))`** |
     *
     * **Las dos primeras las apuntó quien las causó; la tercera no**, y por eso este
     * caso entró en rojo con la P6 (`3ce3056`) y llegó así a `origin/main`. No es
     * descuido de nadie en particular: **este caso vive en la testsuite `Unit` y aquí
     * la cifra se publica casi siempre con `--testsuite=Contrato`**, que no lo
     * ejecuta. Es el aviso de `CLAUDE.md` sobre las dos poblaciones de tests
     * ocurriendo — *«un aviso que ya está escrito no protege solo»*.
     *
     * Lo encontró la integración del 19 sep por la noche, corriendo `php artisan
     * test` entero por primera vez en días.
     *
     * **El 49 y el 53 del §105 siguen sin remedir**: la población que cruzaban con
     * los cuatro clientes era 93 y es 92. Ningún test de este repositorio puede
     * hacer esa medición, y por eso se dice aquí en vez de arreglarse aquí.
     *
     * ## 20 sep 2026 — cruza `obligatoria`, y esta vez el que se mueve es el
     * DETECTOR, no la columna
     *
     * `28 / 65` pasa a `27 / 66` y la suma de **92** a **91**. La columna es
     * `obligatoria` —`unidades` y `subunidades` en el volcado—, y **no la cruza
     * nadie que decida con ella**: la cruza `fbcdda1`
     * (*«aplicar deja de BORRAR la rejilla y la actualiza en su sitio»*), que
     * cambió el `DELETE` + `INSERT` de `PlantillaNotasController` por un `UPDATE`.
     * Lo que casa es esto, medido sobre el árbol y no deducido del diff:
     *
     *     WHERE id = ?', [ $unidad->definicion, $unidad->porcentaje, $unidad->obligatoria
     *
     * O sea: la heurística busca `where|and|or|on|…` a menos de 120 caracteres
     * **delante** del nombre, y aquí el `WHERE` es el de la propia consulta y el
     * nombre es un **parámetro que se escribe**. El `UPDATE` los pone a esa
     * distancia; el `INSERT` de antes, no.
     *
     * **Por eso este renglón no se parece a los tres de `por_defecto`.** Aquéllos
     * registraban un interruptor que empezaba o dejaba de decidir; éste registra
     * que la señal de la herramienta **acertó menos**. `obligatoria` se sigue
     * escribiendo y nadie la lee para decidir: la población real de «no la lee
     * nadie» **no se movió**, y la que se movió es la que mide el detector.
     *
     * > **Y de ahí lo que hay que hacer con el 49 y el 53, que no es lo mismo que
     * > las otras veces.** Se remiden con `tools/interruptores-que-nadie-lee.py`,
     * > que lleva esta misma heurística, así que darían otro par de números por un
     * > `UPDATE` de la plantilla. **Afinar la señal —no contar el nombre cuando va
     * > dentro de la lista de parámetros— vale más que remedir con ella**, y no se
     * > hace aquí porque tocar el criterio mueve los tres montones a la vez y eso
     * > es una medición entera, no el arreglo de un CI. Queda dicho y fechado.
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
