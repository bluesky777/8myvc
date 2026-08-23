<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Borrar una subunidad obedece al periodo, que es lo que un test decía y no era.
 *
 * De los siete métodos que escriben en `SubunidadesController`, seis piden
 * `pueden_editar_notas` desde la §27 y `deleteDestroy` no lo pedía — mientras su
 * gemelo exacto, `UnidadesController::deleteDestroy`, sí. Y borra un componente
 * calificable y **recalcula las definitivas de la asignatura** en la línea
 * siguiente.
 *
 * Lo que lo mantuvo abierto un mes fue una frase. El docblock de
 * `UnidadesTest::test_no_se_restaura_una_subunidad_con_el_periodo_cerrado` decía
 * que «`subunidades/update` y `subunidades/destroy` sí piden el periodo» para
 * justificar por qué había que cerrar `restore`. Uno de los dos sí; el otro no.
 *
 * > **Una afirmación sobre el código de al lado envejece igual que el código, y
 * > ésta nació ya vieja.** Escrita dentro de un test verde se lee como una
 * > medición, y es lo que hace que nadie vuelva. Ver 05 §80.
 */
class SubunidadDestroyTest extends CasoDeContrato
{
    /** Con el periodo cerrado no se borra, y la fila sigue entera. */
    public function test_con_el_periodo_cerrado_no_se_borra_la_subunidad(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($c->token)->deleteJson('/api/subunidades/destroy/'.$c->subunidad_id)
            ->assertStatus(400);

        $this->assertNull(DB::table('subunidades')->where('id', $c->subunidad_id)->value('deleted_at'),
            'Con el periodo cerrado se borró la subunidad igual, y con ella se recalculan las definitivas.');
    }

    /**
     * Con el periodo abierto se borra, y **queda firmado**.
     *
     * La otra mitad: un candado puesto de más apagaría la rejilla de unidades del
     * profesor. Se comprueba además `deleted_by`, que este método sí ponía y que un
     * arreglo torpe —meter el guard entre el `save()` y el `delete()`— se llevaría
     * por delante.
     */
    public function test_con_el_periodo_abierto_se_borra_y_queda_firmado(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 1]);

        $this->withToken($c->token)->deleteJson('/api/subunidades/destroy/'.$c->subunidad_id)
            ->assertStatus(200);

        $fila = DB::table('subunidades')->where('id', $c->subunidad_id)->first();

        $this->assertNotNull($fila->deleted_at, 'La subunidad no se borró con el periodo abierto.');
        $this->assertSame((int) $c->user_id, (int) $fila->deleted_by,
            'Se dejó de anotar quién borró la subunidad.');
    }

    /**
     * El periodo se deriva de la fila y no del cuerpo.
     *
     * `deleteDestroy` recibe `periodo_id` en el cuerpo para el recálculo de
     * definitivas que hace después, así que la tentación de comprobar el permiso
     * con **ese** valor es real y es exactamente lo que la §27 existe para no
     * volver a hacer: el cliente elegiría el permiso que se le comprueba mientras
     * borra en otro sitio.
     *
     * Se manda un `periodo_id` de un periodo **abierto** mientras el de la
     * subunidad está cerrado. Tiene que seguir diciendo que no.
     */
    public function test_no_vale_mandar_en_el_cuerpo_un_periodo_abierto(): void
    {
        $c = $this->contexto();

        $otro = DB::selectOne('SELECT id FROM periodos WHERE year_id = ? AND id <> ? AND deleted_at IS NULL
            ORDER BY id LIMIT 1', [$c->year_id, $c->periodo_id]);

        $this->assertNotNull($otro, 'El seed necesita un segundo periodo para que este test mida algo.');

        DB::table('periodos')->where('id', $c->periodo_id)->update(['profes_pueden_editar_notas' => 0]);
        DB::table('periodos')->where('id', $otro->id)->update(['profes_pueden_editar_notas' => 1]);

        $this->withToken($c->token)->deleteJson('/api/subunidades/destroy/'.$c->subunidad_id, [
            'asignatura_id' => $c->asignatura_id,
            'periodo_id' => $otro->id,
            'num_periodo' => 1,
        ])->assertStatus(400);

        $this->assertNull(DB::table('subunidades')->where('id', $c->subunidad_id)->value('deleted_at'),
            'Mandando otro periodo en el cuerpo se saltó el candado del periodo de la fila.');
    }

    /** Un profesor y una subunidad suya, colgando de una unidad de su periodo. */
    private function contexto(): object
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $periodo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id]);

        $asignatura = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura en el año del profesor.');

        $unidadId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'definicion' => 'Unidad de pruebas',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subunidadId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId,
            'definicion' => 'Subunidad de pruebas',
            'porcentaje' => 100,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'token' => $token,
            'user_id' => (int) $usuario->id,
            'periodo_id' => (int) $periodo->id,
            'year_id' => (int) $periodo->year_id,
            'asignatura_id' => (int) $asignatura->id,
            'subunidad_id' => $subunidadId,
        ];
    }
}
