<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La nota de comportamiento, que sale en el boletín y no la miraba nadie.
 *
 * Seis de las ocho rutas sin comprobar. Lo que sale de aquí y **necesita una
 * decisión** está en la §40: este controlador **no comprueba el periodo en
 * ninguna de sus ocho rutas**, y la nota de comportamiento es una nota —el año
 * tiene hasta un conmutador, `mostrar_nota_comport_boletin`, para sacarla o no—.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class NotaComportamientoTest extends CasoDeContrato
{
    /** Un profesor con su periodo y un alumno matriculado en su año. */
    private function contexto(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $grupo = DB::selectOne('SELECT g.id FROM grupos g
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE g.year_id = ? AND g.deleted_at IS NULL ORDER BY g.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($grupo, 'El seed necesita un grupo con alumnos en el año del profesor.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$grupo->id]);

        return (object) ['token' => $token, 'periodo' => $periodo,
            'grupo_id' => $grupo->id, 'alumno_id' => $alumno->alumno_id];
    }

    /** Anotar la nota la escribe, en el periodo del profesor. */
    public function test_guardar_la_nota_la_escribe_en_el_periodo_del_profesor(): void
    {
        $c = $this->contexto();

        DB::table('nota_comportamiento')
            ->where('alumno_id', $c->alumno_id)->where('periodo_id', $c->periodo->id)->delete();

        $r = $this->withToken($c->token)->postJson('/api/nota_comportamiento/store', [
            'alumno_id' => $c->alumno_id,
            'nota' => 45,
        ]);

        $r->assertStatus(201);

        $fila = DB::table('nota_comportamiento')->where('id', $r->json('id'))->first();

        $this->assertEquals(45, $fila->nota);
        $this->assertEquals($c->periodo->id, $fila->periodo_id,
            'La nota no se escribió en el periodo del profesor.');
    }

    /**
     * `crear` es la hermana de `store` y **el periodo lo elige el cuerpo**.
     *
     * No es el fallo de la §27 —no hay bandera que saltarse, porque este
     * controlador no comprueba ninguna— pero es la misma forma, y por eso se fija:
     * si algún día se le pone candado, el candado tiene que mirar este
     * `periodo_id` y no el del profesor.
     */
    public function test_crear_escribe_en_el_periodo_que_diga_el_cuerpo(): void
    {
        $c = $this->contexto();

        $otro = DB::selectOne('SELECT id FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$c->periodo->year_id, $c->periodo->id]);

        $this->assertNotNull($otro, 'El año del profesor tiene un solo periodo.');

        DB::table('nota_comportamiento')
            ->where('alumno_id', $c->alumno_id)->where('periodo_id', $otro->id)->delete();

        $this->withToken($c->token)->putJson('/api/nota_comportamiento/crear', [
            'alumno_id' => $c->alumno_id,
            'periodo_id' => $otro->id,
            'nota' => 38,
        ])->assertStatus(200);

        $this->assertNotNull(DB::table('nota_comportamiento')
            ->where('alumno_id', $c->alumno_id)->where('periodo_id', $otro->id)->first(),
            'La nota no se escribió en el periodo que nombró el cuerpo.');
    }

    /** Editar y borrar hacen lo que dicen. */
    public function test_editar_y_borrar_la_nota(): void
    {
        $c = $this->contexto();

        $id = DB::table('nota_comportamiento')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'periodo_id' => $c->periodo->id,
            'nota' => 30,
        ]);

        $this->withToken($c->token)
            ->putJson('/api/nota_comportamiento/update/'.$id, ['nota' => 49])
            ->assertStatus(200);

        $this->assertEquals(49, DB::table('nota_comportamiento')->where('id', $id)->value('nota'));

        $this->withToken($c->token)
            ->deleteJson('/api/nota_comportamiento/destroy/'.$id)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('nota_comportamiento')->where('id', $id)->value('deleted_at'),
            'Borrar la nota es a la papelera, no de verdad.');
    }

    /**
     * **Con el periodo cerrado se sigue pudiendo todo**, y eso es lo que hay que
     * decidir.
     *
     * Este controlador no llama a `pueden_editar_notas()` en ninguna de sus ocho
     * rutas, mientras que uniformes —que también es disciplina— sí. El test
     * afirma el comportamiento de hoy **a propósito**: el día que se decida
     * cerrarlo, falla, y ese es su trabajo. Ver 05 §40.
     */
    public function test_con_el_periodo_cerrado_la_nota_de_comportamiento_se_sigue_escribiendo(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('year_id', $c->periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $id = DB::table('nota_comportamiento')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'periodo_id' => $c->periodo->id,
            'nota' => 20,
        ]);

        $this->withToken($c->token)
            ->putJson('/api/nota_comportamiento/update/'.$id, ['nota' => 50])
            ->assertStatus(200);

        $this->assertEquals(50, DB::table('nota_comportamiento')->where('id', $id)->value('nota'),
            'Si esto falla es que ya se cerró: hay que quitar este test y su §40.');
    }

    /**
     * Y `detailed` **escribe dentro de un GET**, que es lo mismo que hace el PIAR.
     *
     * `crearVerifNota()` crea la fila del alumno que no la tenga. Funciona y nadie
     * se queja, pero significa que **esa ruta no se puede cachear** ni servir
     * desde una réplica de lectura. Anotado aquí y en la §35.4 para que el día que
     * alguien lo intente sepa por qué no.
     */
    public function test_el_listado_crea_las_notas_que_faltan(): void
    {
        $c = $this->contexto();

        DB::table('nota_comportamiento')->where('periodo_id', $c->periodo->id)->delete();

        $antes = DB::selectOne('SELECT COUNT(*) c FROM nota_comportamiento
            WHERE periodo_id = ? AND deleted_at IS NULL', [$c->periodo->id])->c;

        $this->withToken($c->token)
            ->getJson('/api/nota_comportamiento/detailed/'.$c->grupo_id)
            ->assertStatus(200);

        $despues = DB::selectOne('SELECT COUNT(*) c FROM nota_comportamiento
            WHERE periodo_id = ? AND deleted_at IS NULL', [$c->periodo->id])->c;

        $this->assertGreaterThan($antes, $despues,
            'Un GET que escribe dejó de escribir: si es a propósito, quítese este test.');
    }
}
