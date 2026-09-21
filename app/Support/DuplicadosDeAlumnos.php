<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Las fichas que parecen la misma persona, para poder arreglarlas.
 *
 * **POR QUÉ HAY DUPLICADOS, que es la pregunta de la que sale todo esto.** `alumnos.documento`
 * no tiene índice UNIQUE, y no es un descuido: `ImportarController.php:516-521` lo explica —«hay
 * filas históricas con el documento vacío o repetido, y un índice único ahí haría fallar el ALTER
 * en los colegios que las tengan»—. Así que la base **no impide** dos fichas iguales, el alta a
 * mano sólo avisaba, y el resultado se mide: en el docker, 21 sep 2026, colegio `simonbolivar`:
 *
 *     27 documentos repetidos  ->  55 fichas
 *     63 nombres repetidos     -> 130 fichas
 *
 * Y el patrón es siempre el mismo. Un año nuevo, una ficha nueva:
 *
 *     id  25  DAVID ALEJANDRO ARAQUE GUERRERO  TI  creado 2018  años 1,5,6,7,8  178 definitivas
 *     id 521  David Alejandro Araque Guerrero  RC  creado 2019  años 2,3         80 definitivas
 *
 * El salto de **RC a TI** —Registro Civil a Tarjeta de Identidad— es el caso más común de todos, y
 * ya estaba escrito en el repo: `EnsayoDeLaImportacion.php:457` lo llama así y dice que «hoy se
 * duplica en silencio».
 *
 * **LOS DOS CRITERIOS, y por qué se devuelven aparte.** Documento igual es casi seguro; nombre
 * igual con documento distinto puede ser un hermano, un primo o dos personas que se llaman igual.
 * Mezclarlos en una sola lista obligaría a quien mira a adivinar de cuál de los dos viene cada
 * fila, y la decisión que hay detrás —fusionar— no se deshace.
 *
 * **LO QUE NO DECIDE.** No propone superviviente. La ficha con más matrículas no es
 * necesariamente la buena: el alumno puede haber seguido en la nueva y tener la vieja abandonada.
 * Eso lo mira una persona, y por eso cada ficha viene con su historial al lado.
 */
class DuplicadosDeAlumnos
{
    /** Grupos devueltos por criterio. Una pantalla de arreglo no se recorre de mil en mil. */
    private const TOPE = 60;

    /**
     * @return array{
     *   por_documento: list<array<string,mixed>>,
     *   por_nombre: list<array<string,mixed>>,
     *   total_documento: int,
     *   total_nombre: int
     * }
     */
    public static function listar(): array
    {
        $porDocumento = self::agrupar(
            'SELECT TRIM(a.documento) AS clave, GROUP_CONCAT(a.id) AS ids, COUNT(*) AS cuantas
             FROM alumnos a
             WHERE a.deleted_at IS NULL AND TRIM(COALESCE(a.documento, "")) <> ""
             GROUP BY TRIM(a.documento) HAVING cuantas > 1
             ORDER BY cuantas DESC, clave');

        /*
         * El de nombre EXCLUYE a los que ya salen por documento: son la misma pareja vista dos
         * veces, y quien arregla la primera vería la segunda como pendiente.
         *
         * `TRIM` y nada más: la colación `utf8mb4_unicode_ci` ya ignora mayúsculas y tildes, que
         * es justo la forma del duplicado real («DAVID ALEJANDRO» / «David Alejandro»).
         */
        $porNombre = self::agrupar(
            'SELECT CONCAT(TRIM(a.nombres), " ", TRIM(COALESCE(a.apellidos, ""))) AS clave,
                    GROUP_CONCAT(a.id) AS ids, COUNT(*) AS cuantas
             FROM alumnos a
             WHERE a.deleted_at IS NULL
               AND TRIM(COALESCE(a.documento, "")) NOT IN (
                   SELECT TRIM(documento) FROM (
                       SELECT documento FROM alumnos
                       WHERE deleted_at IS NULL AND TRIM(COALESCE(documento, "")) <> ""
                       GROUP BY TRIM(documento) HAVING COUNT(*) > 1
                   ) repes
               )
             GROUP BY clave HAVING cuantas > 1
             ORDER BY cuantas DESC, clave');

        return [
            'por_documento' => array_slice($porDocumento, 0, self::TOPE),
            'por_nombre' => array_slice($porNombre, 0, self::TOPE),
            'total_documento' => count($porDocumento),
            'total_nombre' => count($porNombre),
        ];
    }

    /**
     * Un grupo, con sus fichas y el historial de cada una.
     *
     * @return list<array<string,mixed>>
     */
    private static function agrupar(string $sql): array
    {
        $grupos = DB::select($sql);

        if ($grupos === []) {
            return [];
        }

        $todos = [];
        foreach ($grupos as $g) {
            foreach (explode(',', (string) $g->ids) as $id) {
                $todos[] = (int) $id;
            }
        }

        $fichas = self::fichasDe($todos);
        $contexto = AlumnosParecidos::contextoDe($todos);

        $salida = [];
        foreach ($grupos as $g) {
            $ids = array_map('intval', explode(',', (string) $g->ids));

            $salida[] = [
                'clave' => $g->clave,
                'cuantas' => (int) $g->cuantas,
                'fichas' => array_map(static fn ($id) => [
                    'alumno_id' => $id,
                    'nombres' => $fichas[$id]->nombres ?? '',
                    'apellidos' => $fichas[$id]->apellidos ?? null,
                    'documento' => $fichas[$id]->documento ?? null,
                    'tipo_doc_nombre' => $fichas[$id]->tipo_doc_nombre ?? null,
                    'fecha_nac' => $fichas[$id]->fecha_nac ?? null,
                    'foto_nombre' => $fichas[$id]->foto_nombre ?? null,
                    'creado' => $fichas[$id]->created_at ?? null,
                    'matriculas' => $contexto[$id]['matriculas'] ?? 0,
                    'ultimo_year' => $contexto[$id]['ultimo_year'] ?? null,
                    'ultimo_grupo' => $contexto[$id]['ultimo_grupo'] ?? null,
                    'ultimo_estado' => $contexto[$id]['ultimo_estado'] ?? null,
                    'definitivas' => $contexto[$id]['definitivas'] ?? 0,
                ], $ids),
            ];
        }

        return $salida;
    }

    /** @param list<int> $ids @return array<int,object> */
    private static function fichasDe(array $ids): array
    {
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select(
            'SELECT a.id, a.nombres, a.apellidos, a.documento, a.fecha_nac, a.created_at,
                    i.nombre AS foto_nombre, td.abrev AS tipo_doc_nombre
             FROM alumnos a
             LEFT JOIN images i ON i.id = a.foto_id AND i.deleted_at IS NULL
             LEFT JOIN tipos_documentos td ON td.id = a.tipo_doc
             WHERE a.id IN ('.$huecos.')', $ids);

        $porId = [];
        foreach ($filas as $f) {
            $porId[(int) $f->id] = $f;
        }

        return $porId;
    }
}
