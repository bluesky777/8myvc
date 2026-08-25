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
 * ## Y el no concluyente de hoy es un hallazgo de este mismo lote
 *
 * `consultas-en-bucle.py --control` compara el mismo fichero **antes y después de un
 * commit**, y para eso llama a `git show`. **Dentro del contenedor eso no funciona en
 * un worktree**: el `.git` de un árbol de trabajo es un fichero que apunta a una ruta
 * **del host** (`/Users/.../8myvc/.git/worktrees/12`), que no existe en `/app`. `git`
 * contesta *«not a git repository»*.
 *
 * **O sea que ese control se escribió y se verificó en el host, y la suite corre
 * dentro.** Es el instrumento correcto sobre el objeto equivocado, otra vez, y esta
 * vez en el control mismo. Lo que lo salva es que **devuelve 2 y lo dice**, en vez de
 * un `OK` que nadie podría distinguir de uno real.
 */
class AutopruebasDeLasHerramientasTest extends TestCase
{
    /**
     * Las que hoy no pueden concluir dentro del contenedor, con su motivo.
     *
     * **Es una lista corta a propósito.** Cada entrada es una autoprueba que no se
     * está ejerciendo donde corre la suite, o sea una herramienta cuyo número nadie
     * está comprobando de verdad.
     */
    private const NO_CONCLUYENTES = [
        'consultas-en-bucle.py' => 'usa `git show` para comparar dos versiones del mismo '
            .'fichero, y en un worktree el `.git` apunta a una ruta del host que no existe '
            .'dentro del contenedor.',
    ];

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
            $this->assertArrayHasKey($nombre, self::NO_CONCLUYENTES,
                "`{$nombre}` salió NO CONCLUYENTE y no está en la lista de las que no pueden "
                .'concluir aquí. Eso significa que su control **dejó de ejercerse** y nadie '
                ."está comprobando su número.\n\n".$texto);

            $this->markTestSkipped($nombre.': no concluyente — '.self::NO_CONCLUYENTES[$nombre]);
        }

        $this->assertArrayNotHasKey($nombre, self::NO_CONCLUYENTES,
            "`{$nombre}` está apuntada como NO CONCLUYENTE y hoy concluye (exit {$codigo}). "
            .'Quítala de la lista: una excepción que ya no hace falta esconde la siguiente.');

        $this->assertSame(0, $codigo,
            "La autoprueba de `{$nombre}` FALLA. El detector no reproduce su propia respuesta "
            .'conocida, así que **sus listas no valen** hasta que se sepa si está roto él o si '
            .'su control cita algo que ya no existe — **son dos cosas distintas** y esta prueba '
            ."no las distingue.\n\n".$texto);
    }
}
