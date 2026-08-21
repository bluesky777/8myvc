<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT alumnos/show`, que entrega la ficha completa de quien se le pida.
 *
 * Es la hermana de la §34 —`GET api/alumnos`, que entregaba el directorio del
 * colegio— pero en detalle y con más columnas: documento, tipo de sangre, EPS,
 * dirección, teléfono, religión, sisbén, deuda y **`nee` y `nee_descripcion`**,
 * que son las necesidades educativas especiales.
 *
 * La ruta lleva solo `auth.token`. Tiene una rama para acudientes que sí
 * comprueba —«No es tu acudido»— y **un `else` que cubre a todos los demás**,
 * incluido un alumno, buscando por `a.id` sin mirar de quién es.
 *
 * Y no lo cazó `persona.propia` por una razón que su propio docblock había
 * previsto: **el identificador aquí se llama `id`**, y la lista de nombres que el
 * guard reconoce tiene `alumno_id`, `user_id`, `persona_id`… pero no `id` a secas.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §41.
 */
class AlumnosShowTest extends CasoDeContrato
{
    /** Un alumno del seed y otro que no es él. */
    private function dosAlumnos(): array
    {
        $mio = DB::selectOne('SELECT a.id, a.user_id, u.username FROM alumnos a
            INNER JOIN users u ON u.id = a.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE a.deleted_at IS NULL AND u.tipo = "Alumno" ORDER BY a.id LIMIT 1');

        $this->assertNotNull($mio, 'El seed necesita un alumno con cuenta.');

        $ajeno = DB::selectOne('SELECT id, documento, nombres FROM alumnos
            WHERE deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$mio->id]);

        return [$mio, $ajeno];
    }

    /** Un alumno no saca la ficha de otro alumno. */
    public function test_un_alumno_no_saca_la_ficha_de_otro(): void
    {
        [$mio, $ajeno] = $this->dosAlumnos();

        $r = $this->withToken($this->tokenDe($mio->username))
            ->putJson('/api/alumnos/show', ['id' => $ajeno->id]);

        // Lo que se comprueba es **el resultado**: que los datos del otro no
        // salgan. El código exacto importa menos que eso.
        $this->assertNotEquals(200, $r->getStatusCode(),
            'Un alumno recibió 200 pidiendo la ficha de otro.');

        $this->assertStringNotContainsString((string) $ajeno->documento, (string) $r->getContent(),
            'La ficha del otro alumno salió en la respuesta.');
    }

    /** Y la suya sí, que es para lo que la pantalla existe. */
    public function test_un_alumno_saca_la_suya(): void
    {
        [$mio] = $this->dosAlumnos();

        $r = $this->withToken($this->tokenDe($mio->username))
            ->putJson('/api/alumnos/show', ['id' => $mio->id]);

        $r->assertStatus(200);
        $this->assertEquals($mio->id, $r->json('alumno.alumno_id'),
            'El alumno dejó de poder ver su propia ficha.');
    }

    /** Un acudiente, la de su acudido y no la de otro: eso ya estaba bien. */
    public function test_un_acudiente_solo_la_de_su_acudido(): void
    {
        $vinculo = DB::selectOne('SELECT p.alumno_id, u.username FROM parentescos p
            INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL
            INNER JOIN users u ON u.id = ac.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');

        $this->assertNotNull($vinculo, 'El seed necesita un acudiente con acudido.');

        $ajeno = DB::selectOne('SELECT id, documento FROM alumnos
            WHERE deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$vinculo->alumno_id]);

        $token = $this->tokenDe($vinculo->username);

        $this->withToken($token)
            ->putJson('/api/alumnos/show', ['id' => $vinculo->alumno_id])
            ->assertStatus(200);

        $r = $this->withToken($token)->putJson('/api/alumnos/show', ['id' => $ajeno->id]);

        $this->assertNotEquals(200, $r->getStatusCode());
        $this->assertStringNotContainsString((string) $ajeno->documento, (string) $r->getContent());
    }

    /** Y el personal sigue viendo la de cualquiera, que es su trabajo. */
    public function test_el_personal_sigue_viendo_la_de_cualquiera(): void
    {
        [, $ajeno] = $this->dosAlumnos();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/alumnos/show', ['id' => $ajeno->id])
            ->assertStatus(200);
    }
}
