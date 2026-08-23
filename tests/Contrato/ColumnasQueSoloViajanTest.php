<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * **Las columnas que el backend no nombra y el cliente sí usa.** §107.
 *
 * `tools/interruptores-que-nadie-lee.py` reparte las 157 columnas `tinyint(1)`
 * del esquema en tres montones, y el primero —**48 columnas que no aparecen en
 * `app/`, `routes/`, `config/` ni los seeders**— lleva escrito al lado: «si
 * llegan al cliente, es por un `SELECT *`».
 *
 * Llegan. **22 de las 48**, todas de `antecedentes`, todas por la misma puerta:
 *
 * ```php
 * // Matriculas\EnfermeriaController::putDatos   (:43 el 23 ago)
 * $consulta = 'SELECT * FROM antecedentes WHERE alumno_id=?';
 * ```
 *
 * Son los antecedentes médicos del alumno: siete `fami_*` (familiares), ocho
 * `patol_*` (patologías) y siete de vacunación. `myvc_front` las pinta y las
 * guarda campo a campo; el backend **no las nombra en ningún sitio**.
 *
 * ## Por qué esto es un test y no una línea en un documento
 *
 * Buscar cualquiera de las 22 en `app/` da **cero apariciones**. Con ese cero
 * delante, la conclusión natural —«esto no lo usa nadie, se puede borrar»— borra
 * la ficha médica de los alumnos, y **nada falla al hacerlo**: no hay ningún
 * `if`, ningún modelo y ninguna consulta que las nombre. Lo único que las sostiene
 * es un `*`.
 *
 * Por eso el caso **no lleva la lista escrita a mano**: la deriva del esquema y
 * del código, igual que la herramienta, y comprueba que la respuesta las trae.
 * Una lista a mano se queda vieja el día que alguien añada la columna 23, que es
 * justo el día en que este test tendría que avisar.
 *
 * > **Una columna que el backend no nombra no es una columna muerta.**
 */
class ColumnasQueSoloViajanTest extends CasoDeContrato
{
    /** Lo que el detector llama «carpetas del backend». */
    private const CARPETAS = ['app', 'routes', 'config', 'database/seeders'];

    /**
     * Las `tinyint(1)` de una tabla, sacadas del volcado del esquema.
     *
     * Del volcado y no de `information_schema` a propósito: el volcado es la
     * verdad de este repo (CLAUDE.md), y es lo que mira la herramienta cuya
     * afirmación este test sostiene. Preguntarle a la base de test daría lo mismo
     * hoy y dejaría de darlo el día que las dos se separen — que es precisamente
     * lo que habría que ver.
     *
     * @return list<string>
     */
    private function booleanasDeLaTabla(string $tabla): array
    {
        $volcado = file_get_contents(base_path('database/schema/mysql-schema.sql'));

        $bloque = null;
        foreach (explode('CREATE TABLE ', $volcado) as $trozo) {
            if (str_starts_with($trozo, '`'.$tabla.'`')) {
                $bloque = $trozo;
                break;
            }
        }

        $this->assertNotNull($bloque, "La tabla `{$tabla}` desapareció del volcado del esquema.");

        preg_match_all('/^\s*`([a-z0-9_]+)`\s+tinyint\(1\)/mi', $bloque, $m);

        return $m[1];
    }

    /** ¿La nombra alguien en el backend? Misma pregunta que hace la herramienta. */
    private function laNombraElBackend(string $columna): bool
    {
        foreach (self::CARPETAS as $carpeta) {
            $directorio = base_path($carpeta);

            if (! is_dir($directorio)) {
                continue;
            }

            $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directorio));

            foreach ($ficheros as $fichero) {
                if ($fichero->isDir() || $fichero->getExtension() !== 'php') {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($columna, '/').'\b/', file_get_contents($fichero->getPathname()))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * **Las que sólo viajan**: booleanas de `antecedentes` que el backend no nombra.
     *
     * Se calculan aquí y no se escriben: si mañana alguien empieza a nombrar una en
     * el código, sale sola de la lista y el caso sigue midiendo lo que dice medir.
     *
     * @return list<string>
     */
    private function lasQueSoloViajan(): array
    {
        return array_values(array_filter(
            $this->booleanasDeLaTabla('antecedentes'),
            fn ($columna) => ! $this->laNombraElBackend($columna)
        ));
    }

    /**
     * La respuesta de la ficha médica trae **todas** las columnas que sólo viajan.
     *
     * `PUT api/enfermeria/datos` lleva `persona.propia`, y el personal del colegio
     * pasa de largo por ese guard —«lo que puede hacer el personal entre sí queda
     * como está»—, así que un token de `Usuario` llega.
     */
    public function test_la_ficha_medica_trae_las_columnas_que_el_backend_no_nombra(): void
    {
        $soloViajan = $this->lasQueSoloViajan();

        $this->assertNotEmpty($soloViajan,
            'Ninguna columna de `antecedentes` viaja sin que el backend la nombre. Si eso es cierto, la §107 se quedó vieja: léela antes de tocar este test.');

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($alumno, 'El seed necesita un alumno.');

        $token = $this->tokenDelPersonalLlano();

        $r = $this->withToken($token)->putJson('/api/enfermeria/datos', ['alumno_id' => $alumno->id]);

        $r->assertStatus(200);

        $antecedentes = $r->json('antecedentes');
        $this->assertIsArray($antecedentes, 'La respuesta dejó de traer el objeto `antecedentes`.');

        $faltan = array_values(array_diff($soloViajan, array_keys($antecedentes)));

        $this->assertSame([], $faltan,
            "Estas columnas ya no llegan al cliente y el backend tampoco las nombra: no las sostiene nada.\n".
            "Si se quitaron a propósito, la ficha médica del alumno perdió esos campos en las dieciséis copias del front.\n".
            'Ver docs/migracion/noche-2026-08-23/g.md §107.');
    }

    /**
     * Y **cuántas son**, fijado aparte y con el número escrito.
     *
     * Va en su propio caso porque mide otra cosa: el de arriba se rompe si la
     * respuesta encoge, y éste si **el esquema** cambia. Los dos números —22 de las
     * 48— son los de la medición del 23 ago 2026, y cualquiera de los dos que se
     * mueva es una pregunta, no un fallo.
     */
    public function test_son_veintidos_y_todas_de_antecedentes(): void
    {
        $this->assertCount(22, $this->lasQueSoloViajan(),
            'Cambió el número de columnas de `antecedentes` que sólo viajan. '.
            'Si subió, hay una nueva que nadie nombra; si bajó, alguien la nombró o la borró. '.
            'Vuelve a correr tools/interruptores-que-nadie-lee.py --clientes y actualiza la §107 con lo que salga.');
    }
}
