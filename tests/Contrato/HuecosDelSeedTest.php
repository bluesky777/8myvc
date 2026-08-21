<?php

namespace Tests\Contrato;

/**
 * Qué partes de la respuesta NO comprueba nadie, aunque los tests estén verdes.
 *
 * Es el contrapeso de toda la red de seguridad. Un snapshot describe la forma de
 * lo que vino, así que **una lista vacía se describe como vacía y a partir de ahí
 * pasa siempre**: no comprueba la forma de sus elementos, porque no hubo
 * elementos. El P0 ya lo cometió con `myimages` y con la lista de deudores, y en
 * los dos casos el test parecía verde.
 *
 * Lo que salta a la vista es cuando el snapshot entero sale vacío. Lo que se
 * cuela es el hueco de un nivel más adentro: la respuesta trae datos, el resto
 * viene lleno, y esa clave concreta se queda sin mirar. `alumnos.situaciones` de
 * un boletín es eso.
 *
 * Aquí se leen los 112 ficheros de snapshot y se saca el mapa entero. Sale de
 * los ficheros y no de repetir las peticiones porque **el snapshot ES la forma**:
 * el resultado es el mismo y no cuesta ni una consulta.
 *
 * Va contra un snapshot y no contra una lista escrita a mano por lo mismo que
 * `AutorizacionTest`: son más de cien entradas, y a ese tamaño una lista a mano
 * deja de leerse. El fichero es la lista, y su diff es lo que hay que mirar.
 *
 * **Qué hacer cuando este test falla**, que es lo único que importa:
 *
 * - *Desapareció un hueco* → alguien cubrió esa parte. Se regenera el snapshot y
 *   se celebra.
 * - *Apareció un hueco nuevo* → o el seed dejó de traer un dato, o la ruta dejó
 *   de devolverlo. **Lo segundo es una regresión y no la ve ningún otro test**,
 *   porque la forma de una lista vacía sigue casando con el snapshot de antes si
 *   el snapshot también estaba vacío. Hay que mirar cuál de las dos es antes de
 *   regenerar.
 *
 * Las familias que hay hoy, para no volver a investigarlas una por una:
 *
 * | Familia | Por qué está vacía |
 * |---|---|
 * | `*.ids` del acta de evaluación | Los contadores del acta traen la lista de matrículas que los componen. Los que valen cero traen la lista vacía, y eso es correcto: el acta del seed no tiene deserciones ni repitentes |
 * | `frases`, `situaciones`, `definiciones` de los boletines | Texto que el profesor escribe a mano por alumno. El seed lo trae para unas asignaturas y no para otras |
 * | `recuperaciones` | `recuperacion_final` no entra en el seed |
 * | `descripciones_typeahead` | Lee de `dis_procesos`, una de las dos tablas que el generador omite por ser el dato más sensible del sistema |
 * | `token` en los contextos de login | El objeto vacío que quedó de JWT. Documentado en `Services\ContextoDeUsuario` |
 * | `perms` del tipo Usuario | El primer `Usuario` del año no tiene rol. La forma de la lista sí está cubierta, por los contextos de Profesor, Alumno y Acudiente |
 * | Las tablas `ws_*` | El módulo de actividades no entra en el seed |
 * | `grupos` de `con-paises-tipos-next-year` | El año siguiente al del seed está borrado en producción. `GruposTest` lo cubre al revés, preguntando desde el año anterior |
 */
class HuecosDelSeedTest extends CasoDeContrato
{
    public function test_los_huecos_son_los_conocidos(): void
    {
        $mapa = [];

        $ficheros = glob(__DIR__.'/Snapshots/*.json');

        $this->assertNotFalse($ficheros, 'No se pudo leer el directorio de snapshots.');

        foreach ($ficheros as $fichero) {
            $nombre = basename($fichero, '.json');

            if ($nombre === self::MAPA) {
                continue;
            }

            $forma = json_decode(file_get_contents($fichero), true);

            if ($forma === [] || $forma === null) {
                $mapa[$nombre] = ['(la respuesta entera)'];

                continue;
            }

            $huecos = $this->clavesVacias($forma);

            if ($huecos !== []) {
                sort($huecos);
                $mapa[$nombre] = $huecos;
            }
        }

        ksort($mapa);

        $this->compararConInstantanea(self::MAPA, $mapa);
    }

    /** El nombre del snapshot de este test, que no se mira a sí mismo. */
    private const MAPA = 'huecos-del-seed';

    /**
     * Las claves cuyo valor es una lista vacía, con su camino en notación de punto.
     *
     * Los índices de lista no se numeran: `formaUnida()` reduce una lista a un
     * solo elemento con la forma de todos, así que `grupos.alumnos` describe la
     * clave de cada alumno y `grupos.0.alumnos` sugeriría que solo pasa con el
     * primero.
     */
    private function clavesVacias($forma, string $camino = ''): array
    {
        if (! is_array($forma)) {
            return [];
        }

        $esLista = array_is_list($forma);
        $vacias = [];

        foreach ($forma as $clave => $valor) {
            $bajo = $esLista ? $camino : ($camino === '' ? (string) $clave : $camino.'.'.$clave);

            if ($valor === []) {
                $vacias[] = $bajo === '' ? '(la respuesta entera)' : $bajo;

                continue;
            }

            $vacias = array_merge($vacias, $this->clavesVacias($valor, $bajo));
        }

        return $vacias;
    }
}
