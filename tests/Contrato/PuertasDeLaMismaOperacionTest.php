<?php

namespace Tests\Contrato;

/**
 * **Cuántas puertas tiene «mandar un alumno a la papelera».** §89.
 *
 * La [§72](../../docs/migracion/05-codigo-muerto-y-roto.md) cerró
 * `editnota/destroy` y escribió al hacerlo la frase que más se ha citado esta
 * noche:
 *
 * > Cerrar una de tres es lo que pasa cuando se arregla **el sitio que se está
 * > mirando y no la operación**.
 *
 * Eran **cuatro**, no tres. Las dos que faltaban —`boletines2/destroy` y
 * `boletines3/destroy`— se cerraron en la §89, **al día siguiente**.
 *
 * ## Qué añade este caso a la §89
 *
 * La §89 comprueba que las cuatro **rechazan** a quien no tiene el criterio, y eso
 * lo hace `BoletinesBorranAlumnosTest`. Lo que no comprobaba nadie —ni entonces ni
 * ahora— es **cuántas puertas hay**. Y ése es exactamente el dato que le faltó a
 * la §72: no se equivocó en el criterio, se equivocó en el censo.
 *
 * > Un test que comprueba que las puertas conocidas están cerradas **no dice nada
 * > de las que no se contaron.**
 *
 * Así que este caso cuenta, y lo hace **leyendo el código**: los métodos que
 * resuelven un alumno por id y lo borran. El día que aparezca un quinto, cae — y
 * cae el día que se escribe, no el día que alguien lo tropiece.
 */
class PuertasDeLaMismaOperacionTest extends CasoDeContrato
{
    /**
     * Las cuatro, con quién las defiende.
     *
     * El valor no es decorativo: si algún día una de ellas cambia de criterio, lo
     * que hay escrito aquí es contra qué compararla.
     */
    private const PUERTAS = [
        'Http/Controllers/AlumnosController.php::deleteDestroy' => 'Autoriza::puedeEditarAlumnos, desde antes de la migración',
        'Http/Controllers/EditnotaController.php::deleteDestroy' => 'Autoriza::puedeEditarAlumnos, puesto por la §72',
        'Http/Controllers/Informes/Boletines2Controller.php::deleteDestroy' => 'Autoriza::puedeEditarAlumnos, puesto por la §89',
        'Http/Controllers/Informes/Boletines3Controller.php::deleteDestroy' => 'Autoriza::puedeEditarAlumnos, puesto por la §89',
    ];

    /**
     * Los métodos de `app/` que resuelven un alumno por id y lo borran.
     *
     * Sin comentarios, que es el arreglo que la §72.5 le hizo a su propio detector:
     * los docblocks de la §89 **citan** `Alumno::find($id)` para explicar el
     * hallazgo, y contarlos sería encontrar lo que se escribió sobre el código.
     *
     * @return list<string>
     */
    private function puertasEnElCodigo(): array
    {
        $encontradas = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($it as $fichero) {
            if ($fichero->isDir() || $fichero->getExtension() !== 'php') {
                continue;
            }

            $codigo = implode('', array_map(
                fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($t) ? $t[1] : $t),
                token_get_all(file_get_contents($fichero->getPathname()))
            ));

            // `[ \t]*` y `static` opcional: el recorte por tabuladores dejaba fuera
            // los ficheros que ya pasaron por pint, y `pint` va fichero a fichero
            // según se tocan. Es la cuarta frontera que se le encontró al detector
            // de escrituras esta misma noche.
            preg_match_all('/\n[ \t]*(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(/', $codigo, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[1] as $i => [$nombre, $inicio]) {
                $fin = $m[0][$i + 1][1] ?? strlen($codigo);
                $cuerpo = substr($codigo, $inicio, $fin - $inicio);

                if (preg_match('/\bAlumno::find(OrFail)?\s*\(/', $cuerpo)
                    && preg_match('/->delete\s*\(/', $cuerpo)) {
                    $encontradas[] = str_replace(app_path().'/', '', $fichero->getPathname()).'::'.$nombre;
                }
            }
        }

        sort($encontradas);

        return $encontradas;
    }

    /**
     * **Son cuatro, y son éstas.**
     *
     * Si aparece una quinta, no es que esté mal: es que **la población creció** y
     * hay que decidir su criterio antes de que alguien la cierre sola.
     */
    public function test_mandar_un_alumno_a_la_papelera_tiene_cuatro_puertas(): void
    {
        $esperadas = array_keys(self::PUERTAS);
        sort($esperadas);

        $this->assertSame($esperadas, $this->puertasEnElCodigo(),
            "Cambió cuántos sitios mandan un alumno a la papelera.\n".
            "Si hay uno NUEVO: la §72 se cerró sobre tres de cuatro por no contar, y la §89 pagó el resto.\n".
            "  Dale su criterio —el que ya tienen las otras— y añádelo aquí, con quién lo defiende.\n".
            "Si FALTA uno: alguien lo borró o lo movió, y hay una sección del 05 que se quedó vieja.\n".
            'Ver docs/migracion/noche-2026-08-23/c.md §89.');
    }
}
