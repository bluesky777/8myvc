<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las autopruebas de `tools/`, **ejecutadas**.
 *
 * CONTROLES-1. Varias herramientas llevan en su cabecera un **control positivo**:
 * *«tiene que encontrar X; si no sale, el detector está roto y su lista no vale»*.
 * Cuatro lo tienen además **ejecutable**, detrás de `--control` o `--autoprueba`.
 *
 * > **Y nadie las corría.** `DefinicionDeLosDetectoresTest` vigila las *definiciones*
 * > que los documentos citan —qué tablas mira cada detector— y eso es otra cosa.
 * > **Las dos piezas existían y no estaban conectadas**: este caso es el cable.
 *
 * *Un control positivo que nadie ejecuta es una intención, no un control* — y uno
 * ejecutable que nadie invoca **es exactamente lo mismo**, sólo que parece mejor.
 *
 * ## Tres resultados, no dos
 *
 *     exit 0   la autoprueba pasa
 *     exit 1   FALLA -> el detector no reproduce su propia respuesta conocida
 *     exit 2   NO CONCLUYENTE -> no pudo ejercerse aquí, y lo dice en vez de mentir
 *
 * **El 2 no es un aprobado y tampoco un fallo**, y por eso no se trata como ninguno
 * de los dos: se fija **cuáles** salen no concluyentes y **por qué**. Si mañana una
 * que hoy concluye deja de hacerlo, este caso cae — que es justo lo que un control
 * sin runner nunca podría avisar.
 *
 * ## Hoy no hay ninguna no concluyente, y cómo se cayó la que había
 *
 * `consultas-en-bucle.py --control` compara el mismo fichero **antes y después de un
 * commit**, y para eso llama a `git show`. Entró aquí marcada NO CONCLUYENTE con este
 * motivo: *«dentro del contenedor eso no funciona»*.
 *
 * **Y el motivo estaba mal.** No es el contenedor: es el **worktree**. El `.git` de un
 * árbol de trabajo es un fichero que apunta a una ruta **del host**
 * (`gitdir: /Users/.../8myvc/.git/worktrees/12`), que no existe en `/app`; el del árbol
 * principal es un directorio de verdad. Medido en los dos sitios, los dos **dentro** del
 * contenedor:
 *
 *     docker exec 8myvc-app-1 python3 /app/tools/consultas-en-bucle.py --control
 *       -> «antes de 2837171: 10 … despues: 4 … OK», exit 0
 *
 *     docker exec -w /app/.worktrees/12 8myvc-app-1 python3 …/consultas-en-bucle.py --control
 *       -> «CONTROL NO CONCLUYENTE: no se pudo leer 2837171^ (¿worktree sin ese commit?)»
 *
 * **La diferencia no es dónde corre, es desde qué árbol.** Y eso cambia la conclusión
 * entera: no era un control que la suite no puede ejercer —eso lo habría dejado sin
 * comprobar para siempre—, era uno que **sólo la noche en paralelo no puede ejercer**.
 *
 * ## Y no lo encontró nadie leyendo: lo encontró la fusión
 *
 * En la rama, medida desde `.worktrees/12`, el caso pasaba —salía 2, estaba en la
 * lista, `skipped`—. **En `main` sale 0 y el caso cae**, que es exactamente lo que este
 * runner se puso a hacer: *«si mañana una que hoy no concluye empieza a concluir, la
 * excepción sobra»*. Funcionó el primer día, contra su propio autor.
 *
 * Es la regla de la cabecera de CLAUDE.md otra vez, y en su segunda forma —la que no se
 * arregla repitiendo la medición—: **el detector contaba bien el síntoma y la causa que
 * le puso al lado era otra.** Repetirlo en el worktree da 2 otra vez, para siempre.
 */
class AutopruebasDeLasHerramientasTest extends TestCase
{
    /**
     * Las que hoy no pueden concluir donde corre la suite, con su motivo.
     *
     * **Vacía, y que lo esté es el resultado — no un descuido.** Cada entrada sería una
     * autoprueba que no se está ejerciendo, o sea una herramienta cuyo número nadie
     * comprueba de verdad; la única que hubo se cayó al fundir (ver la cabecera).
     *
     * **Una que salga 2 desde aquí falla con nombre**, que es lo que se quiere: la
     * alternativa —apuntarla y seguir— es cómo se llega a una lista de excepciones que
     * nadie vuelve a mirar.
     *
     * > **Y si alguien la ve caer corriendo desde un worktree, el sitio donde mirar es
     * > el árbol, no la herramienta.** `consultas-en-bucle.py --control` necesita
     * > `git show`, y en un worktree el `.git` es un fichero que apunta al host. La
     * > suite de una noche en paralelo corre ahí (`docs/migracion/15-la-noche-en-paralelo.md`).
     * >
     * > **Y hay un tercer árbol donde pasó lo mismo por un tercer motivo: el CI.**
     * > `actions/checkout` clona **superficial** de serie, así que `2837171^` no existía
     * > y el control salía 2 en los tres pushes del 25 ago 2026 — la suite entera en
     * > rojo por correo, con 1.515 casos en verde. Arreglado con `fetch-depth: 0` en
     * > `.github/workflows/ci.yml`, no aquí: **la herramienta nunca estuvo mal**, y las
     * > tres veces la respuesta fue la misma —*mira desde qué árbol corre*—.
     *
     * **Método y no `const`**, y es por el análisis: con una constante vacía larastan
     * deduce `array{}` y da por muerta la rama del `skip` entera —tiene razón mientras
     * la lista esté vacía, pero eso convierte «hoy no hay ninguna» en «no puede haber
     * ninguna»—. Con el tipo de retorno declarado, el mecanismo sigue en pie y **volver
     * a apuntar una es añadir una línea aquí**, sin tocar nada más.
     *
     * @return array<string, string>
     */
    private static function noConcluyentes(): array
    {
        return [];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function autopruebas(): array
    {
        return [
            'consultas-en-bucle.py' => ['python3', 'tools/consultas-en-bucle.py --control'],
            'escrituras-sin-auditoria.php' => ['php', 'tools/escrituras-sin-auditoria.php --autoprueba'],
            'quien-escribe-de-verdad.py' => ['python3', 'tools/quien-escribe-de-verdad.py --autoprueba'],
            'secciones-citadas.py' => ['python3', 'tools/secciones-citadas.py --autoprueba'],
            // Era la única que declaraba un control positivo EN PROSA y no tenía nada
            // que lo ejecutara. CONTROLES-1 se lo puso, y al ejecutarlo salió rojo por
            // un motivo que no estaba en la lista de la ficha: **el fallo que citaba se
            // había arreglado** (`0473a9b`). Re-anclado en un caso sintético.
            'verdad-laxa-que-escribe.py' => ['python3', 'tools/verdad-laxa-que-escribe.py --control'],
        ];
    }

    #[DataProvider('autopruebas')]
    public function test_la_autoprueba_de_la_herramienta_se_ejecuta_y_concluye(string $binario, string $orden): void
    {
        $nombre = basename(explode(' ', $orden)[0]);
        $raiz = dirname(__DIR__, 2);

        $salida = [];
        $codigo = 0;
        exec('cd '.escapeshellarg($raiz).' && '.$binario.' '.$orden.' 2>&1', $salida, $codigo);

        $texto = implode("\n", array_slice($salida, -6));

        if ($codigo === 2) {
            // `?? null` porque la lista puede estar vacía —hoy lo está—: sin entrada, este
            // camino muere en la aserción de abajo, que es lo que se quiere.
            $motivo = self::noConcluyentes()[$nombre] ?? null;

            $this->assertNotNull($motivo,
                "`{$nombre}` salió NO CONCLUYENTE y no está en la lista de las que no pueden "
                .'concluir aquí. Eso significa que su control **dejó de ejercerse** y nadie '
                ."está comprobando su número.\n\n".$texto);

            $this->markTestSkipped($nombre.': no concluyente — '.$motivo);
        }

        $this->assertArrayNotHasKey($nombre, self::noConcluyentes(),
            "`{$nombre}` está apuntada como NO CONCLUYENTE y hoy concluye (exit {$codigo}). "
            .'Quítala de la lista: una excepción que ya no hace falta esconde la siguiente.');

        $this->assertSame(0, $codigo,
            "La autoprueba de `{$nombre}` FALLA. El detector no reproduce su propia respuesta "
            .'conocida, así que **sus listas no valen** hasta que se sepa si está roto él o si '
            .'su control cita algo que ya no existe — **son dos cosas distintas** y esta prueba '
            ."no las distingue.\n\n".$texto);
    }
}
