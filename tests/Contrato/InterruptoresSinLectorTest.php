<?php

namespace Tests\Contrato;

/**
 * **Los dos interruptores con casilla en pantalla que no deciden nada.** §107.1.
 *
 * `ws_actividades.can_upload` —«¿puede subir archivos?» en el formulario del
 * examen— y `dis_procesos.deriva_de_tardanzas` —en el formulario del proceso
 * disciplinario— se guardan y **no los lee nadie**: ni el backend, ni
 * `myvc_front` en ninguna de sus 23 ramas, ni `myvc_front_2`, ni `myvc_flutter`.
 * Su única aparición en un cliente es el `ng-model` de la casilla, que es lo
 * contrario de leerlas: es el sitio donde alguien las **enciende**.
 *
 * > Una columna que sólo aparece donde se escribe no tiene lectores: tiene
 * > autores.
 *
 * ## Por qué esto es un test y no sólo una anotación
 *
 * La anotación dice «esto está así». Este caso dice **qué tiene que pasar para
 * que deje de estarlo**: el día que alguien haga que una de las dos decida algo
 * —un `if`, un `WHERE`, una comparación— el caso cae, y entonces la pregunta
 * abierta para Joseth (*«¿esas casillas deben hacer algo?»*) está contestada por
 * los hechos y hay que ir a cerrarla.
 *
 * Un veredicto envejece en silencio; **una condición de caducidad avisa el día
 * que se cumple**, porque quien la cumple es justo quien rompe el test. Es lo que
 * hizo bien el docblock de `CalendarioSoloProfesTest`.
 *
 * ## Lo que NO comprueba
 *
 * No mira los clientes: desde aquí no se puede. Si el arreglo llega por el lado
 * del front —que es lo más probable, porque la casilla es suya— este test sigue
 * verde. Lo que cubre es la mitad que vive en este repositorio.
 */
class InterruptoresSinLectorTest extends CasoDeContrato
{
    /**
     * Las dos, con dónde se encienden. La clave es la columna; el valor, la
     * pantalla, porque sin ella la anotación no se puede contestar.
     */
    private const SIN_LECTOR = [
        'can_upload' => 'ws_actividades — casilla «puede subir archivos» del examen, en editarActividad.html',
        'deriva_de_tardanzas' => 'dis_procesos — formulario del proceso disciplinario',
    ];

    /**
     * Delante del nombre, en la misma cadena: lo que convierte una columna en una
     * decisión. Es la misma señal que usa `tools/interruptores-que-nadie-lee.py`,
     * y `on` entra porque este proyecto filtra en los JOIN tanto como en el WHERE.
     */
    private const DECIDE = '/\b(where|and|or|on|having|when|if|case)\b[^;]{0,120}%s/i';

    /** @return list<string> ficheros PHP de app/ */
    private function ficherosDeApp(): array
    {
        $ficheros = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($it as $f) {
            if (! $f->isDir() && $f->getExtension() === 'php') {
                $ficheros[] = $f->getPathname();
            }
        }

        sort($ficheros);

        return $ficheros;
    }

    /**
     * **Ninguna de las dos decide nada en `app/`.**
     *
     * Se descartan los comentarios antes de mirar: un docblock que explique por qué
     * la columna no se lee **nombra la columna**, y contarlo sería la §72.5 otra vez
     * —un detector que encuentra lo que se escribió sobre él—. Este mismo test es
     * prosa que las nombra, y si viviera en `app/` se contaría a sí mismo.
     */
    public function test_las_dos_siguen_sin_que_nadie_decida_con_ellas(): void
    {
        $deciden = [];

        foreach ($this->ficherosDeApp() as $fichero) {
            $codigo = implode('', array_map(
                fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($t) ? $t[1] : $t),
                token_get_all(file_get_contents($fichero))
            ));

            foreach (array_keys(self::SIN_LECTOR) as $columna) {
                if (preg_match(sprintf(self::DECIDE, preg_quote($columna, '/')), $codigo)) {
                    $deciden[] = $columna.'  en  '.str_replace(app_path().'/', '', $fichero);
                }
            }
        }

        sort($deciden);

        $this->assertSame([], $deciden,
            "Alguien empezó a decidir con uno de estos interruptores, y hasta hoy no los leía nadie:\n  ".
            implode("\n  ", $deciden)."\n\n".
            "Eso contesta la pregunta que estaba abierta para Joseth —«¿esas casillas deben hacer algo?»— por los hechos.\n".
            'Ver docs/migracion/noche-2026-08-23/g.md §107.1, y quítala de la lista de pendientes.');
    }

    /**
     * **Y las dos se siguen escribiendo**, que es la mitad sin la cual el caso de
     * arriba pasaría también si alguien las borrara del esquema.
     *
     * Un interruptor que no lee nadie y que **tampoco se escribe** ya no es una
     * pregunta abierta: es una columna muerta, y eso es otra anotación distinta.
     */
    public function test_las_dos_se_siguen_guardando(): void
    {
        $seEscriben = [];

        foreach ($this->ficherosDeApp() as $fichero) {
            $codigo = file_get_contents($fichero);

            foreach (array_keys(self::SIN_LECTOR) as $columna) {
                if (preg_match('/\b'.preg_quote($columna, '/').'\b/', $codigo)
                    && preg_match('/(INSERT\s+INTO|UPDATE|->'.preg_quote($columna, '/').'\s*=)/i', $codigo)) {
                    $seEscriben[$columna] = true;
                }
            }
        }

        $this->assertSame(array_keys(self::SIN_LECTOR), array_keys($seEscriben),
            'Alguno de los dos interruptores dejó de escribirse. Si se quitó del esquema, ya no es '.
            'una pregunta abierta sino una columna borrada: actualiza la §107.1.');
    }
}
