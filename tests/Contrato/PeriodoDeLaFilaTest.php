<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El candado del periodo, comprobado donde de verdad pesa.
 *
 * `UniformesTest` fija el caso que descubrió la §27 —el que se midió de punta a
 * punta—, y este cubre las otras familias, que son las que la §27.1 daba por
 * difíciles: la unidad, la subunidad, la nota y la definitiva. En cada una el
 * periodo al que se escribe sale de un sitio distinto, y ésa era justamente la
 * razón por la que el arreglo no era de media hora.
 *
 * **Todos miden lo mismo y de la misma forma**: con el año entero cerrado y un
 * periodo cualquiera abierto, nombrar el abierto no deja escribir en el cerrado.
 * Y la mitad simétrica —que escribir en el abierto sigue pasando— está en
 * `UniformesTest`, porque si el arreglo fuera un candado tonto la rejilla de
 * definitivas se apagaría y eso es lo que la §27.1 protegía.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §27.
 */
class PeriodoDeLaFilaTest extends CasoDeContrato
{
    /** Un profesor, su token y el año con todos los periodos cerrados menos uno. */
    private function escenario(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        // `Services\Login` reescribe `users.periodo_id` al entrar, así que el
        // periodo hay que leerlo DESPUÉS del token o se mide otro año.
        $suyo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $abierto = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY numero LIMIT 1',
            [$suyo->year_id, $suyo->id]);

        $this->assertNotNull($abierto, 'El año del profesor tiene un solo periodo: no se puede medir esto.');

        DB::table('periodos')->where('id', $abierto->id)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);

        return (object) ['token' => $token, 'cerrado' => $suyo, 'abierto' => $abierto];
    }

    /** Una unidad de un periodo cerrado del año del profesor. */
    private function unidadDelPeriodo(int $periodoId): ?object
    {
        return DB::selectOne('SELECT id, definicion, porcentaje FROM unidades
            WHERE periodo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$periodoId]);
    }

    public function test_una_unidad_del_periodo_cerrado_no_se_edita_nombrando_el_abierto(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidadDelPeriodo((int) $e->cerrado->id);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades en el periodo del profesor.');
        }

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$unidad->id, [
            'definicion' => 'reescrita con el periodo cerrado',
            'porcentaje' => $unidad->porcentaje,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);

        $this->assertSame($unidad->definicion,
            DB::table('unidades')->where('id', $unidad->id)->value('definicion'),
            'La unidad del periodo cerrado se reescribió igual.');
    }

    public function test_una_subunidad_hereda_el_periodo_de_su_unidad(): void
    {
        $e = $this->escenario();
        $unidad = $this->unidadDelPeriodo((int) $e->cerrado->id);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades en el periodo del profesor.');
        }

        $subunidad = DB::selectOne('SELECT id, definicion, porcentaje FROM subunidades
            WHERE unidad_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$unidad->id]);

        if ($subunidad === null) {
            $this->markTestSkipped('Esa unidad no tiene subunidades en el seed.');
        }

        // La subunidad no lleva `periodo_id`: cuelga de la unidad y la unidad sí.
        // Es una de las que hacían que esto no fuera un arreglo de media hora.
        $this->withToken($e->token)->putJson('/api/subunidades/update/'.$subunidad->id, [
            'definicion' => 'reescrita con el periodo cerrado',
            'porcentaje' => $subunidad->porcentaje,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);

        $this->assertSame($subunidad->definicion,
            DB::table('subunidades')->where('id', $subunidad->id)->value('definicion'));
    }

    /**
     * La rejilla de definitivas, que es la llamada que más pesa.
     *
     * El front manda `nf_id` y `num_periodo` **sin `periodo_id`**, así que la
     * opción barata de la §27.1 —exigir que `num_periodo` y `periodo_id`
     * concuerden— no habría cerrado justamente ésta. Ahora el periodo sale de
     * `notas_finales.periodo_id`, que es la fila que se escribe.
     */
    public function test_una_definitiva_del_periodo_cerrado_no_se_toca_nombrando_el_abierto(): void
    {
        $e = $this->escenario();

        $nf = DB::selectOne('SELECT id, nota FROM notas_finales
            WHERE periodo_id = ? ORDER BY id LIMIT 1', [$e->cerrado->id]);

        if ($nf === null) {
            $this->markTestSkipped('El seed no tiene notas finales en el periodo del profesor.');
        }

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/update', [
            'nf_id' => $nf->id,
            'nota' => 1,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);

        $this->assertEquals($nf->nota,
            DB::table('notas_finales')->where('id', $nf->id)->value('nota'),
            'La definitiva del periodo cerrado se cambió igual.');
    }

    /**
     * Un reordenado que toca dos periodos no pasa por el que esté abierto.
     *
     * `subunidades/update-orden-varias` mueve subunidades entre dos unidades, y
     * las dos unidades pueden ser de periodos distintos. Se comprueban los dos y
     * basta que uno esté cerrado: escribir la mitad de un reordenado deja el
     * orden inconsistente, que es peor que no escribir nada.
     */
    public function test_un_reordenado_entre_dos_periodos_exige_los_dos_abiertos(): void
    {
        $e = $this->escenario();

        $cerrada = $this->unidadDelPeriodo((int) $e->cerrado->id);
        $abierta = $this->unidadDelPeriodo((int) $e->abierto->id);

        if ($cerrada === null || $abierta === null) {
            $this->markTestSkipped('El seed no tiene una unidad en cada uno de los dos periodos.');
        }

        $this->withToken($e->token)->putJson('/api/subunidades/update-orden-varias', [
            'sortHash1' => [],
            'sortHash2' => [],
            'unidad1_id' => $abierta->id,
            'unidad2_id' => $cerrada->id,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);
    }

    /**
     * Y un periodo que ya no está no abre nada.
     *
     * Si una fila apunta a un periodo borrado debajo de ella, el resolutor
     * devuelve el id pero la consulta de banderas no encuentra la fila. Eso
     * cuenta como **cerrado** y no como «no se pudo derivar»: no se inventa
     * permiso.
     *
     * El montaje está al revés que los demás a propósito. Aquí el periodo del
     * profesor se deja **abierto** y el de la unidad se borra, porque de la otra
     * forma el test pasaba también sin el arreglo —se comprobó desactivándolo— y
     * un test que pasa de las dos maneras no comprueba nada. Con el periodo del
     * profesor abierto, el camino viejo diría que sí.
     */
    public function test_una_fila_que_apunta_a_un_periodo_borrado_no_deja_escribir(): void
    {
        $e = $this->escenario();

        // El del profesor, abierto: es lo que respondería el camino de antes.
        DB::table('periodos')->where('id', $e->cerrado->id)
            ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);

        $unidad = $this->unidadDelPeriodo((int) $e->abierto->id);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades en el otro periodo.');
        }

        DB::table('periodos')->where('id', $e->abierto->id)->update(['deleted_at' => now()]);

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$unidad->id, [
            'definicion' => 'reescrita con el periodo borrado',
            'porcentaje' => $unidad->porcentaje,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);

        $this->assertSame($unidad->definicion,
            DB::table('unidades')->where('id', $unidad->id)->value('definicion'));
    }

    /**
     * La recuperación final es del año, así que los quiere abiertos todos.
     *
     * `recuperacion_final` no tiene `periodo_id` —guarda alumno, asignatura,
     * `year` y nota—, o sea que no hay fila de la que derivar un periodo, y el
     * permiso que la gobierna sí es por periodo. Antes se leía `num_periodo` del
     * cuerpo, que es el hueco de la §27; el front nunca lo manda ahí, así que la
     * puerta estaba abierta y no la usaba nadie.
     *
     * Joseth eligió el 21 ago 2026 exigir que estén abiertos **todos**, porque lo
     * que se toca es del año entero. Con uno cerrado no se puede, y eso es lo
     * elegido a sabiendas.
     */
    public function test_nivelar_exige_todos_los_periodos_del_ano_abiertos(): void
    {
        $e = $this->escenario();   // deja abierto solo uno de los del año

        // El seed no trae ninguna recuperación final, así que se monta aquí dentro
        // de la transacción del test. Es la séptima vez que hace falta hacerlo:
        // el seed es una rebanada del grafo de claves foráneas y las tablas que
        // nadie referencia se quedan vacías.
        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ?
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$e->cerrado->year_id]);

        $asignatura = DB::selectOne('SELECT asig.id FROM asignaturas asig
            INNER JOIN grupos g ON g.id = asig.grupo_id AND g.year_id = ?
            WHERE asig.deleted_at IS NULL ORDER BY asig.id LIMIT 1', [$e->cerrado->year_id]);

        $this->assertNotNull($alumno, 'El seed necesita un alumno en el año del profesor.');
        $this->assertNotNull($asignatura, 'El seed necesita una asignatura en ese año.');

        $rfId = DB::table('recuperacion_final')->insertGetId([
            'alumno_id' => $alumno->id,
            'asignatura_id' => $asignatura->id,
            'year' => DB::table('years')->where('id', $e->cerrado->year_id)->value('year'),
            'nota' => 3,
        ]);

        $rf = (object) ['id' => $rfId, 'nota' => 3];

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 1,
            'num_periodo' => $e->abierto->numero,
        ])->assertStatus(400);

        $this->assertEquals($rf->nota,
            DB::table('recuperacion_final')->where('id', $rf->id)->value('nota'),
            'La recuperación se cambió con periodos cerrados en el año.');

        // Y con los cuatro abiertos sí, que es la mitad que impide que esto sea
        // un candado que no deja trabajar a nadie.
        DB::table('periodos')->where('year_id', $e->cerrado->year_id)
            ->update(['profes_pueden_nivelar' => 1]);

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 1,
        ])->assertStatus(200);

        $this->assertEquals(1,
            DB::table('recuperacion_final')->where('id', $rf->id)->value('nota'));
    }
}
