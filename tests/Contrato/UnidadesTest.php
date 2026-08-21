<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las unidades: la rejilla con la que se calcula la nota de una asignatura.
 *
 * Sale de la cobertura —`UnidadesController` tenía 7 de sus 11 rutas sin ninguna
 * respuesta comprobada— y lo que encontró es la §27 otra vez: **el interruptor
 * con el que el colegio cierra el periodo no lo miraban tres de sus métodos**.
 * Ver 05 §47.
 *
 * Lo que hace decidible esto es que su controlador gemelo sí lo pide:
 * `SubunidadesController` comprueba el periodo al crear y al reordenar, y aquí
 * faltaba en los dos sitios equivalentes. No es una regla nueva —la §40 ya
 * decidió que el interruptor cierra las notas—, es la misma sin aplicar.
 */
class UnidadesTest extends CasoDeContrato
{
    /**
     * Un profesor con su periodo CERRADO, y una asignatura de su año.
     *
     * El periodo se lee **después** del token: `Services\Login` reescribe
     * `users.periodo_id` al entrar, así que leerlo antes mide otro año.
     */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($asignatura, 'El seed no tiene asignaturas en el año del profesor.');

        return (object) [
            'token' => $token,
            'periodo' => (int) $suyo->id,
            'year_id' => (int) $suyo->year_id,
            'asignatura' => (int) $asignatura->id,
        ];
    }

    private function abrirElPeriodo(int $yearId): void
    {
        DB::table('periodos')->where('year_id', $yearId)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);
    }

    private function unidad(object $e): int
    {
        DB::insert('INSERT INTO unidades (definicion, porcentaje, periodo_id, asignatura_id, orden, created_at, updated_at)
                    VALUES ("Unidad de prueba", 50, ?, ?, 0, ?, ?)',
            [$e->periodo, $e->asignatura, now(), now()]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Crear una unidad con el periodo cerrado devolvía **201**, mientras editarla
     * un segundo después devolvía 400. Y su gemelo `subunidades/store` sí lo
     * pedía desde la §27.
     */
    public function test_no_se_crea_una_unidad_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $antes = DB::table('unidades')->where('asignatura_id', $e->asignatura)->count();

        $this->withToken($e->token)->postJson('/api/unidades', [
            'definicion' => 'Nueva', 'porcentaje' => 50, 'asignatura_id' => $e->asignatura,
        ])->assertStatus(400);

        $this->assertSame($antes, DB::table('unidades')->where('asignatura_id', $e->asignatura)->count());
    }

    /** Y con el periodo abierto sí, que es lo que no se puede romper. */
    public function test_con_el_periodo_abierto_la_unidad_se_crea(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);

        $r = $this->withToken($e->token)->postJson('/api/unidades', [
            'definicion' => 'Nueva', 'porcentaje' => 50, 'asignatura_id' => $e->asignatura,
        ]);

        $r->assertStatus(201);
        $this->assertSame('Nueva', $r->json('definicion'));
        $this->assertSame($e->periodo, (int) $r->json('periodo_id'));
    }

    /**
     * Reordenar es escribir en la rejilla, y no lo miraba nadie. El gemelo
     * `subunidades/update-orden-varias` sí, con su comentario de la §27 al lado.
     */
    public function test_no_se_reordenan_unidades_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) $unidad => 7]],
        ])->assertStatus(400);

        $this->assertSame(0, (int) DB::table('unidades')->where('id', $unidad)->value('orden'));
    }

    public function test_con_el_periodo_abierto_se_reordenan(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        $this->abrirElPeriodo($e->year_id);

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) $unidad => 7]],
        ])->assertStatus(200);

        $this->assertSame(7, (int) DB::table('unidades')->where('id', $unidad)->value('orden'));
    }

    /**
     * Restaurar devuelve la unidad con su `porcentaje` a la rejilla, así que es
     * escribir en las notas igual que borrarla — y `unidades/destroy` sí lo pedía.
     */
    public function test_no_se_restaura_una_unidad_con_el_periodo_cerrado(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $this->withToken($e->token)->putJson('/api/unidades/restore/'.$unidad, [])->assertStatus(400);

        $this->assertNotNull(DB::table('unidades')->where('id', $unidad)->value('deleted_at'),
            'Sigue en la papelera.');
    }

    public function test_con_el_periodo_abierto_se_restaura(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);
        $this->abrirElPeriodo($e->year_id);

        $this->withToken($e->token)->putJson('/api/unidades/restore/'.$unidad, [])->assertStatus(200);

        $this->assertNull(DB::table('unidades')->where('id', $unidad)->value('deleted_at'));
    }

    /**
     * `Unidad::find()` devolvía null con un id que no existe y `->orden` sobre
     * null era un 500. Es la misma forma que la §44: el 500 tapaba que el cliente
     * había nombrado una fila que no está.
     */
    public function test_reordenar_una_unidad_que_no_existe_es_404(): void
    {
        $e = $this->escenario();
        $this->abrirElPeriodo($e->year_id);
        $maximo = (int) DB::table('unidades')->max('id');

        $this->withToken($e->token)->putJson('/api/unidades/update-orden', [
            'sortHash' => [[(string) ($maximo + 1000) => 1]],
        ])->assertStatus(404);
    }

    /** Cobertura de las dos lecturas del controlador que nadie miraba. */
    public function test_las_unidades_de_un_profesor_traen_sus_asignaturas(): void
    {
        $e = $this->escenario();
        $profesor = DB::selectOne('SELECT p.id FROM profesores p
            INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.id = ?', [$e->asignatura]);

        $r = $this->withToken($e->token)
            ->putJson('/api/unidades/de-profesor', ['profesor_id' => $profesor->id]);

        $r->assertStatus(200);
        $this->assertNotNull($r->json('info_profesor'));
        $this->assertIsArray($r->json('asignaturas'));
    }

    public function test_las_unidades_eliminadas_de_una_asignatura(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidad($e);
        DB::table('unidades')->where('id', $unidad)->update(['deleted_at' => now()]);

        $r = $this->withToken($e->token)->putJson('/api/unidades/eliminadas/'.$e->asignatura, []);

        $r->assertStatus(200);
        // La respuesta viene envuelta en `unidades_eliminadas`, no es una lista
        // suelta. Se comprobó leyéndola, que es de lo que va esta serie.
        $this->assertContains(
            $unidad,
            array_map(fn ($u) => (int) $u['id'], $r->json('unidades_eliminadas'))
        );
    }
}
