<?php

namespace Tests\Contrato;

/**
 * **Cuántos métodos de `PerfilesController` operan sobre GRUPO y no sobre
 * persona.** §130.
 *
 * La cabecera de `PerfilesApi.ts` del front avisa de que son **cinco** —`show`,
 * `destroy`, `forcedelete`, `restore` y `trashed`— y esa lista es la que ha
 * guiado los arreglos: el lote E midió y cerró las cinco.
 *
 * **Son seis.** La que falta es `getIndex`, o sea **`GET api/perfiles`**, el
 * índice del recurso: devuelve los grupos del año.
 *
 * ## Por qué la lista del front no podía tenerla
 *
 * `PerfilesApi.ts` es el fichero donde el front declara **lo que llama**, y el
 * front **no llama al índice**: no hay `listar()` en esa factoría. Comprobado en
 * las 23 ramas de `myvc_front`, en `myvc_front_2`, en `myvc_flutter` y **en el
 * bundle desplegado**, donde la factoría se lee entera y la de al lado
 * —`PeriodosApi`— sí tiene su `listar(){return i.get(s)}`, que es el control que
 * demuestra que esa forma se habría visto.
 *
 * > **Una población leída del fichero de un cliente está acotada por lo que ese
 * > cliente llama.** No es una lista de lo que hay: es una lista de lo que se usa.
 *
 * Es la misma forma que la §89 —cerrar sobre la población equivocada— con el
 * matiz que la hace difícil de ver: aquí la lista **no estaba mal**, estaba
 * **completa para lo que era**. El error no es del front; es de haberla leído
 * como si fuera el censo.
 *
 * ## Qué se hace con la sexta: nada, y ya estaba decidido
 *
 * `GET api/perfiles` **ya está juzgada**, en la lista de excepciones de familia de
 * `AutorizacionTest`, con estas palabras: *«no devuelve perfiles: devuelve los
 * GRUPOS del año. Es un catálogo con el nombre cambiado»*. Va con el resto de
 * lecturas de catálogo que esperan la decisión del
 * [08](../../docs/migracion/08-revision-idor.md), y su gemela `GET api/grupos`
 * lleva exactamente el mismo guard —ninguno más que `auth.token`— así que **no hay
 * asimetría que cerrar**.
 *
 * Este test no arregla nada. Fija **el tamaño de la población**, que es lo que
 * nadie tenía: si mañana aparece un séptimo método operando sobre grupos, se ve
 * el día que se escribe y no cuando alguien lo tropiece.
 */
class PoblacionDePerfilesTest extends CasoDeContrato
{
    /**
     * **Los ocho métodos que nombran `grupos`, con el veredicto de cada uno.**
     *
     * Ocho los encuentra el grep; **seis** son la población y **dos** sólo cruzan
     * la tabla para enriquecer una lectura de persona. Esa diferencia **no la ve un
     * grep** y por eso los ocho se leyeron uno a uno: es la regla de la casa —un
     * detector da sitios donde mirar, nunca una lista de fallos— aplicada a la
     * única pregunta que este lote tenía.
     *
     * La clave es el método; el valor, **dónde está su veredicto**, porque un
     * miembro sin veredicto es justo lo que este caso existe para señalar.
     */
    private const NOMBRAN_GRUPOS = [
        // La población: operan SOBRE un grupo.
        'getIndex' => 'población — GET api/perfiles devuelve los grupos del año; excepción de familia en AutorizacionTest, pendiente de la decisión del 08',
        'getShow' => 'población — GET api/perfiles/show/{id} devuelve el grupo; §104 alineó su `Profesor::find` con el de su gemela',
        'deleteDestroy' => 'población — DELETE api/perfiles/destroy/{id} manda un GRUPO a la papelera; §100',
        'deleteForcedelete' => 'población — la tercera puerta al mismo grupo; §100',
        'putRestore' => 'población — restaura un grupo; exige superusuario, igual que su gemela',
        'getTrashed' => 'población — la papelera de GRUPOS; copia fiel de grupos/trashed',

        // Sólo cruzan la tabla: operan sobre PERSONAS.
        'getUsername' => 'sólo cruza — une `grupos` para traer el grupo del alumno que busca; opera sobre personas',
        'getUsuariosall' => 'sólo cruza — la rejilla de usuarios del colegio; une `grupos` por la misma razón',
    ];

    /** Los que de verdad operan sobre un grupo, que es la población del lote. */
    private const POBLACION = ['getIndex', 'getShow', 'deleteDestroy', 'deleteForcedelete', 'putRestore', 'getTrashed'];

    /** El fichero, sin comentarios: un docblock que hable de grupos no es tocar grupos. */
    private function codigoSinComentarios(): string
    {
        $ruta = app_path('Http/Controllers/Perfiles/PerfilesController.php');

        $this->assertFileExists($ruta, 'PerfilesController cambió de sitio: este caso mide otra cosa hasta que se actualice.');

        return implode('', array_map(
            fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($t) ? $t[1] : $t),
            token_get_all(file_get_contents($ruta))
        ));
    }

    /**
     * **Lo que el grep encuentra: ocho, y son estos ocho.**
     *
     * Se mide por el cuerpo del método y no por una lista escrita a mano, que es
     * exactamente lo que falló: la del front era de cinco.
     */
    public function test_son_ocho_los_metodos_que_nombran_grupos(): void
    {
        $codigo = $this->codigoSinComentarios();

        preg_match_all('/\n\tpublic function (\w+)\s*\(/', $codigo, $m, PREG_OFFSET_CAPTURE);

        $encontrados = [];

        foreach ($m[1] as $i => [$nombre, $inicio]) {
            $fin = $m[0][$i + 1][1] ?? strlen($codigo);
            $cuerpo = substr($codigo, $inicio, $fin - $inicio);

            if (preg_match('/\bGrupo::|\bgrupos\b/', $cuerpo)) {
                $encontrados[] = $nombre;
            }
        }

        sort($encontrados);

        $esperados = array_keys(self::NOMBRAN_GRUPOS);
        sort($esperados);

        $this->assertSame($esperados, $encontrados,
            "Cambió qué métodos de PerfilesController nombran `grupos`.\n".
            "Si hay uno NUEVO, hay que LEERLO y decidir si opera sobre un grupo o sólo cruza la tabla: son dos cosas distintas y el grep no las separa.\n".
            "Si es de la población, es un miembro más de una lista que el front declara de cinco.\n".
            'Ver docs/migracion/noche-2026-08-23/o.md §130.');
    }

    /**
     * **Y de los ocho, seis son la población.**
     *
     * Va aparte porque es lo que **no** se midió: se leyó. Si algún día se
     * automatiza la distinción, este caso es el que dice qué tenía que dar.
     */
    public function test_seis_de_los_ocho_operan_sobre_un_grupo(): void
    {
        $poblacion = array_keys(array_filter(
            self::NOMBRAN_GRUPOS,
            fn ($veredicto) => str_starts_with($veredicto, 'población')
        ));

        sort($poblacion);
        $esperada = self::POBLACION;
        sort($esperada);

        $this->assertSame($esperada, $poblacion,
            'La población y los veredictos dejaron de coincidir dentro de este mismo fichero.');

        $this->assertCount(6, $poblacion,
            'La población de PerfilesController dejó de ser de seis. El front la declara de cinco, '.
            'y esa diferencia es el §130: una población leída del fichero de un cliente está acotada '.
            'por lo que ese cliente llama.');
    }
}
