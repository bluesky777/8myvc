<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Que nadie vuelva a escribir `<= porc_final` en `app/`.
 *
 * ## Por qué hace falta un centinela y no bastó arreglar los sitios
 *
 * El 13 sep 2026 se cambió la regla de banda en **trece** sitios
 * (`docs/migracion/36-la-nota-decimal-y-las-bandas-enteras.md`): de
 * `porc_inicial <= nota <= porc_final` —que deja **un hueco en cada frontera** en
 * cuanto la nota tiene decimales— a `porc_inicial <= nota AND nota < porc_final + 1`.
 *
 * **Y al día siguiente de terminar el barrido ya eran quince.** Dos sitios más
 * —`Informes\BoletinPorCompetenciasController` y `DesempenosController`— se
 * escribieron **en paralelo**, mientras el censo ya estaba cerrado, y nacieron con
 * la regla vieja. Uno de los dos llevaba un docblock explicando que se había
 * escrito **para coincidir** con el `left join` que acababa de cambiar.
 *
 * Ése es el motivo exacto de este fichero, y no es «por si acaso»:
 *
 * > **Un barrido está completo cuando se corre e incompleto cuando aterriza.** Lo
 * > que envejece no es el predicado —se puede escribir perfecto— sino **el árbol**,
 * > y con varias sesiones escribiendo a la vez caduca en minutos. Repetir el
 * > barrido sólo produce otra foto; lo único que lo cierra es un test.
 *
 * Es la misma forma que este repositorio ya usa contra las listas a mano:
 * `information_schema` para las tablas del año nuevo, `SHOW COLUMNS` para sus
 * columnas, `route:list` para las rutas. Aquí la fuente de verdad es el propio
 * código.
 *
 * ## Qué mira, y por qué tokeniza en vez de hacer `grep`
 *
 * **Los comentarios no cuentan.** Media docena de docblocks de `app/` *describen*
 * la regla vieja para explicar por qué se cambió —incluido el del doc 36 citado en
 * `DesempenosController`— y un `grep` los contaría como infracciones. Se tokeniza,
 * se tiran `T_COMMENT` y `T_DOC_COMMENT`, y se busca sobre lo que queda: código y
 * cadenas, que es donde viven tanto el PHP como el SQL crudo de las consultas.
 *
 * ## Lo que NO comprueba
 *
 * No comprueba que la regla sea **correcta** —eso lo hace
 * `LaBandaLlegaHastaElSiguienteEnteroTest` con notas de verdad— ni encuentra una
 * tercera forma de escribir la comparación que no use `<=` ni `>=` (un
 * `min()`/`max()`, un `between`). **Cierra la puerta por la que ya se coló dos
 * veces**, no todas las puertas.
 */
class CentinelaDeLaReglaDeLaBandaTest extends TestCase
{
    /**
     * `porc_final` como operando inmediato de `<=` o `>=`, en cualquiera de las dos
     * escrituras. La regla buena —`nota < porc_final + 1`— usa `<` y no casa.
     *
     * **Las dos direcciones, y ésa es la mitad que costó el hallazgo**: el barrido
     * original buscó sólo `porc_final >=` y los dos sitios que se le escaparon
     * invertían los operandos (`$nota <= $banda->porc_final`).
     */
    /**
     * La raíz del repositorio, calculada y no pedida a Laravel.
     *
     * `base_path()` no existe sin arrancar la aplicación, y arrancarla aquí sería
     * pagar el arranque entero para leer ficheros de disco. Este centinela no toca
     * la base ni el contenedor: es `token_get_all` sobre 235 ficheros y tarda menos
     * de un segundo.
     */
    private const RAIZ = __DIR__.'/../..';

    private const PROHIBIDO = '/porc_final\s*(<=|>=)|(<=|>=)\s*(\(float\)\s*)?[\$\w\->\.]*porc_final/';

    public function test_ningun_sitio_de_app_compara_con_menor_o_igual_contra_porc_final(): void
    {
        $infractores = $this->infractores(self::RAIZ.'/app');

        $this->assertSame([], $infractores,
            "Hay comparaciones con la regla vieja de banda, que deja un hueco en cada frontera\n"
            ."cuando la nota tiene decimales (doc 36). Use `nota < porc_final + 1`:\n\n  "
            .implode("\n  ", $infractores));
    }

    /**
     * El control: el centinela **tiene que ver rojo** con una comparación mala
     * puesta a mano. Un centinela verde que no se ha visto rojo no ha demostrado
     * nada — y éste barre 235 ficheros, así que un patrón que no case con nada
     * pasaría por «todo en orden».
     */
    public function test_el_centinela_ve_las_dos_escrituras_de_la_regla_vieja(): void
    {
        $malas = [
            'el SQL crudo, como estaba en los joins' => 'e.porc_inicial<=n.nota and e.porc_final>=n.nota',
            'el PHP, con los operandos al revés' => '$nota <= $banda->porc_final',
            'el PHP con el cast que llevaba uno de los dos' => '$nota <= (float) $banda->porc_final',
        ];

        foreach ($malas as $porque => $codigo) {
            $this->assertSame(1, preg_match(self::PROHIBIDO, $codigo),
                "El centinela no vería esta forma: {$porque}.");
        }

        // Y la buena no salta, que es la otra mitad: un centinela que casa con todo
        // obliga a desactivarlo el primer día.
        foreach ([
            'e.porc_inicial<=n.nota and n.nota < e.porc_final + 1',
            '($escala_val->porc_inicial <= $nota) && ($nota < $escala_val->porc_final + 1)',
            'e.porc_inicial<=nf.nota_final_per1 and nf.nota_final_per1 < e.porc_final + 1',
        ] as $buena) {
            $this->assertSame(0, preg_match(self::PROHIBIDO, $buena),
                "El centinela salta con la regla BUENA: {$buena}");
        }
    }

    /** @return list<string> `fichero:linea` de cada infracción, sin comentarios. */
    private function infractores(string $raiz): array
    {
        $encontrados = [];

        $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $codigo = (string) file_get_contents($fichero->getPathname());

            if (! str_contains($codigo, 'porc_final')) {
                continue;
            }

            foreach ($this->lineasDeCodigo($codigo) as $linea => $texto) {
                if (preg_match(self::PROHIBIDO, $texto) === 1) {
                    $corto = str_replace(self::RAIZ.'/', '', $fichero->getPathname());
                    $encontrados[] = $corto.':'.$linea;
                }
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    /**
     * El fichero por líneas, con los comentarios vaciados y la numeración intacta.
     *
     * Se vacían en vez de quitarse para que el número de línea del aviso siga
     * siendo el del fichero: un centinela que nombra una línea que no existe hace
     * perder más tiempo del que ahorra.
     *
     * @return array<int, string>
     */
    private function lineasDeCodigo(string $codigo): array
    {
        $limpio = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Se conservan los saltos de línea del comentario, nada más.
                $limpio .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $limpio .= is_array($token) ? $token[1] : $token;
        }

        $lineas = [];

        foreach (explode("\n", $limpio) as $i => $texto) {
            $lineas[$i + 1] = $texto;
        }

        return $lineas;
    }
}
