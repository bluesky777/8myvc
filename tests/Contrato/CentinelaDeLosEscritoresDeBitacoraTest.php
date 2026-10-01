<?php

namespace Tests\Contrato;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * **Cero escritores de `bitacoras`** desde el 30 sep 2026 (contrato 5).
 *
 * Hasta ese día este centinela fijaba los trece `INSERT INTO bitacoras` del
 * proyecto, fichero a fichero y con el reloj de cada uno, para que
 * `tools/salud-de-la-bitacora.php` supiera repartir las filas entre UTC y Bogotá.
 * Joseth decidió dejar de alimentar la tabla: el rastro va sólo a `auditoria` y su
 * escritor único, `App\Services\Auditoria`. La tabla se queda —la leen
 * `GET bitacoras/{user_id?}` (congelado), los historiales de antes del corte y la
 * limpieza—, pero ya no recibe filas nuevas.
 *
 * O sea que la lista pasó a estar vacía, y **una lista vacía sin centinela dura
 * hasta el siguiente que copie un escritor viejo**, que es justo lo que pasa
 * cuando se clona un método de un controlador a otro. Si esto se pone rojo, la
 * línea va por `Auditoria::registrar()` (o `AuditarFila`), no a `bitacoras`.
 *
 * El caso que mira la tabla en vez del código, recorriendo los caminos que
 * escribían en ella, es
 * `AuditoriaDeLosDiezEscritoresTest::test_los_caminos_que_escribian_en_bitacoras_ya_no_la_tocan`.
 */
class CentinelaDeLosEscritoresDeBitacoraTest extends TestCase
{
    #[Test]
    public function nadie_escribe_en_bitacoras(): void
    {
        $encontrados = $this->escritoresPorFichero();

        $this->assertSame([], $encontrados,
            "Alguien volvió a escribir en `bitacoras`, que dejó de alimentarse el 30 sep 2026\n".
            "(contrato 5). El rastro va sólo a `auditoria`, con `Auditoria::registrar()` o\n".
            "`AuditarFila`.\n\n".
            'Encontrados: '.json_encode($encontrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * **Un comentario que menciona `INSERT INTO bitacoras` NO es un escritor.**
     *
     * La primera versión de esto contaba con `preg_match_all` sobre el texto del
     * fichero, comentarios incluidos, y **falló de verdad el 24 ago 2026**: el
     * docblock de `App\Services\Auditoria` explicaba por qué existe —*«hoy hay 10
     * INSERT INTO bitacoras repartidos en 8 ficheros»*— y el centinela cantó once.
     * Con cero escritores importa más: los comentarios que dicen «aquí había un
     * INSERT y se quitó el 30 sep» son justo los que quedan en el código.
     */
    #[Test]
    public function un_comentario_que_lo_menciona_no_cuenta_como_escritor(): void
    {
        $conComentario = <<<'PHP'
            <?php
            // Aquí antes había un INSERT INTO bitacoras y se quitó; y un new Bitacora.
            /** Ver los INSERT INTO bitacoras del proyecto y DB::table('bitacoras')->insert. */
            class X {
                public function y() {
                    DB::insert('INSERT INTO bitacoras (created_by) VALUES (?)', [1]);
                }
            }
            PHP;

        $this->assertSame(1, $this->contarEnCodigo($conComentario),
            'Cuenta menciones en vez de escrituras: es el fallo del 24 ago, cuando '.
            'el docblock de `App\Services\Auditoria` contó como un escritor.');

        $soloComentarios = <<<'PHP'
            <?php
            // INSERT INTO bitacoras
            /* new Bitacora; */
            /** DB::table('bitacoras')->insert([]) */
            PHP;

        $this->assertSame(0, $this->contarEnCodigo($soloComentarios));
    }

    /**
     * Y las otras formas de escribir, que el SQL a mano no cubre: el modelo y el
     * constructor de consultas. Leer, borrar lógicamente (`DELETE bitacoras/destroy`)
     * y la limpieza **no** cuentan: no dejan rastro nuevo.
     */
    #[Test]
    public function caza_tambien_el_modelo_y_el_constructor_de_consultas(): void
    {
        $escrituras = <<<'PHP'
            <?php
            $b = new Bitacora;
            $c = new \App\Models\Bitacora();
            Bitacora::create([]);
            DB::table('bitacoras')->insert([]);
            DB::table("bitacoras")->insertGetId([]);
            DB::statement("REPLACE INTO bitacoras (id) VALUES (1)");
            PHP;

        $this->assertSame(6, $this->contarEnCodigo($escrituras));

        $lecturas = <<<'PHP'
            <?php
            DB::table('bitacoras')->where('id', 1)->count();
            DB::select('SELECT * FROM bitacoras WHERE id = ?', [1]);
            DB::update('UPDATE bitacoras SET deleted_at=? WHERE id=?', [1, 1]);
            DB::delete('DELETE FROM bitacoras WHERE created_at < ?', [1]);
            $x = Bitacora::class;
            PHP;

        $this->assertSame(0, $this->contarEnCodigo($lecturas));
    }

    /**
     * Las escrituras que hay ahora mismo en `app/`, por fichero.
     *
     * Sobre el código y no sobre una petición a propósito: lo que se fija es **el
     * código que puede escribir**, no el que escribió hoy. Un escritor que sólo se
     * dispara en un caso raro cuenta igual.
     *
     * @return array<string, int>
     */
    private function escritoresPorFichero(): array
    {
        $encontrados = [];

        /** @var iterable<\SplFileInfo> $ficheros */
        $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $cuantos = $this->contarEnCodigo((string) file_get_contents($fichero->getPathname()));

            if ($cuantos > 0) {
                $encontrados[str_replace(base_path().'/', '', $fichero->getPathname())] = $cuantos;
            }
        }

        return $encontrados;
    }

    /**
     * Cuántas escrituras —no menciones— hay en un trozo de código.
     *
     * Sobre `token_get_all()` y no con una regex sobre el texto: los comentarios
     * llegan como `T_COMMENT`/`T_DOC_COMMENT` y se tiran antes de buscar. El SQL se
     * busca dentro de las cadenas; el modelo y `DB::table(...)->insert` en el código
     * que queda sin comentarios.
     */
    private function contarEnCodigo(string $codigo): int
    {
        $cuantos = 0;
        $sinComentarios = '';

        foreach (token_get_all($codigo) as $token) {
            if (! is_array($token)) {
                $sinComentarios .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $sinComentarios .= $token[1];

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $cuantos += preg_match_all('/(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?bitacoras\b/i', $token[1]);
            }
        }

        $cuantos += preg_match_all('/\bnew\s+\\\\?(?:App\\\\Models\\\\)?Bitacora\b/', $sinComentarios);
        $cuantos += preg_match_all(
            '/\bBitacora::(?:create|forceCreate|insert|insertGetId|insertOrIgnore|firstOrCreate|updateOrCreate|upsert)\s*\(/',
            $sinComentarios
        );
        $cuantos += preg_match_all(
            '/table\(\s*[\'"]bitacoras[\'"]\s*\)\s*->\s*(?:insert|insertGetId|insertOrIgnore|upsert|updateOrInsert)\s*\(/',
            $sinComentarios
        );

        return $cuantos;
    }
}
