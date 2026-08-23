<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `publicaciones/restaurar`: la única escritura del lote que pide solo token — §100.
 *
 * Y el hallazgo es que **no hace falta más**: la ruta no lleva guard, pero el
 * método llama a `exigeQueLaPublicacionSeaSuya()` antes del `UPDATE`. Está
 * defendida por dentro, y `AutorizacionTest` la tiene en su lista de exenciones
 * escrita con esa razón.
 *
 * Lo que faltaba era **la respuesta**: la exención dice quién *no* pasa, y nadie
 * había mirado qué contesta la que sí pasa ni qué escribe. Es la lección de
 * siempre —**medir una ruta no es haberla juzgado**— y aquí en su versión menos
 * intuitiva: la tabla de rutas no es la autoridad sobre si algo está defendido,
 * ni para bien ni para mal.
 */
class RestaurarUnaPublicacionTest extends CasoDeContrato
{
    /** Cada uno saca de la papelera la suya, y la respuesta es la cadena de siempre. */
    public function test_el_autor_restaura_la_suya(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $suya = $this->publicacionEnPapeleraDe($alumno);

        $r = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/restaurar', ['publi_id' => $suya]);

        $r->assertStatus(200);

        // **No es JSON.** Devuelve la cadena `Restaurada` a pelo, sin comillas, y
        // por eso se compara el cuerpo y no `->json()`, que aquí revienta con
        // «Invalid JSON was returned from the route». Es contrato con dieciséis
        // copias del front igual que lo sería un objeto, y menos visible: quien
        // «arregle» esto devolviendo `['mensaje' => 'Restaurada']` no rompe
        // ningún código HTTP, rompe el `.then()` del front.
        $this->assertSame('Restaurada', $r->getContent(),
            'Cambió lo que contesta restaurar: lo lee el front para pintar el muro.');

        $this->assertNull(DB::table('publicaciones')->where('id', $suya)->value('deleted_at'),
            'Contestó 200 y la publicación siguió en la papelera.');
    }

    /**
     * La de otro, no — y el rechazo es 403 y no un 200 vacío.
     *
     * Un alumno contra la publicación de un profesor: es el caso que la §22 midió
     * para borrar y editar, y ésta es la tercera de la familia.
     */
    public function test_nadie_restaura_la_publicacion_de_otro(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $ajena = $this->publicacionEnPapeleraDe($this->usuarioDeTipo('Profesor'), 'Profesor');

        $r = $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/restaurar', ['publi_id' => $ajena]);

        $this->assertSame(403, $r->status(), 'Un alumno restauró la publicación de un profesor.');

        $this->assertNotNull(DB::table('publicaciones')->where('id', $ajena)->value('deleted_at'),
            'El rechazo la sacó de la papelera antes de contestar que no.');
    }

    /**
     * Restaurar deja el `deleted_by` puesto, y eso no es cosmético.
     *
     * El `UPDATE` pone `deleted_at = null` y no toca `deleted_by`, así que una
     * publicación viva conserva el nombre de quien la borró una vez. Nadie lo lee
     * hoy —`ultimas_publicaciones()` filtra por `deleted_at`— pero es justo la
     * columna que miraría el colegio si alguien reclama, y diría que la borró
     * quien la borró **la vez anterior**. Se fija medido, no juzgado.
     */
    public function test_restaurar_no_limpia_quien_la_habia_borrado(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $suya = $this->publicacionEnPapeleraDe($alumno);

        DB::update('UPDATE publicaciones SET deleted_by = ? WHERE id = ?', [$alumno->id, $suya]);

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/publicaciones/restaurar', ['publi_id' => $suya])
            ->assertStatus(200);

        $this->assertSame((int) $alumno->id,
            (int) DB::table('publicaciones')->where('id', $suya)->value('deleted_by'),
            'Ahora restaurar limpia `deleted_by`: es mejor, pero es un cambio y va escrito.');
    }

    /**
     * Un id que no existe da 403 y no 404, y es a propósito.
     *
     * `exigeQueLaPublicacionSeaSuya()` busca la fila con el id, el `persona_id` y
     * el `tipo_persona` de quien pide en la misma consulta: para el que pregunta,
     * una publicación que no existe y una que no es suya son indistinguibles, y
     * decirlo con 404 sería contar si existe. Se fija para que nadie lo
     * «arregle» a 404 sin saber que eso es una decisión.
     */
    public function test_un_id_que_no_existe_no_dice_que_no_existe(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $inexistente = (int) DB::table('publicaciones')->max('id') + 1000;

        $this->assertSame(403,
            $this->withToken($this->tokenDe($alumno->username))
                ->putJson('/api/publicaciones/restaurar', ['publi_id' => $inexistente])->status(),
            'Restaurar una publicación inexistente cambió de código — §100.');
    }

    /** Una publicación suya, ya en la papelera. Se deshace con la transacción del test. */
    private function publicacionEnPapeleraDe(object $usuario, string $tipo = 'Alumno'): int
    {
        $personaId = DB::table($tipo === 'Alumno' ? 'alumnos' : 'profesores')
            ->where('user_id', $usuario->id)->value('id');

        $this->assertNotNull($personaId, "Esa cuenta de {$tipo} no tiene ficha.");

        DB::insert(
            'INSERT INTO publicaciones (persona_id, tipo_persona, contenido, para_todos,
                deleted_at, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?, ?)',
            [$personaId, $tipo, 'prueba del lote E', '2026-08-23 01:00:00',
                '2026-08-23 01:00:00', '2026-08-23 01:00:00']
        );

        return (int) DB::getPdo()->lastInsertId();
    }
}
