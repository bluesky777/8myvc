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
     * Y sin `nf_id` ni `num_periodo`, **422 diciendo qué falta** — no un 403.
     *
     * Hasta el 24 ago 2026 un cuerpo así llegaba a
     * `PeriodoDeLaFila::porNumero($user, null)`, no resolvía ningún periodo, y el
     * rechazo salía **por la guarda de permisos**: el profesor leía «no tienes
     * permiso para modificar definitivas» cuando lo que pasaba era que faltaba un
     * dato.
     *
     * Es la §3.4 del [10](../../docs/migracion/10-definitivas.md) —dos fallos
     * distintos con la misma cara— y de los caros, porque el mensaje **manda a
     * investigar a la persona equivocada**: quien lo recibe va a mirar los roles
     * del profesor, no el cuerpo de la petición.
     *
     * **Este test comprueba el código Y que no se escribió nada.** Un 422 con la
     * fila escrita sería peor que el 403: la única forma de distinguir «rechazó»
     * de «rechazó después de escribir» es contar filas, no leer la respuesta.
     */
    public function test_sin_periodo_dice_que_falta_el_periodo_y_no_que_falta_permiso(): void
    {
        $e = $this->escenario();

        $antes = (int) DB::table('notas_finales')->count();

        $respuesta = $this->withToken($e->token)->putJson('/api/definitivas_periodos/update', [
            'nota' => 5,
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertSee('num_periodo', false);

        $this->assertSame($antes, (int) DB::table('notas_finales')->count(),
            'Rechazó por falta de periodo pero escribió una fila igual.');
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

    /**
     * El año del profesor entero cerrado, sin dejar ninguno abierto.
     *
     * `escenario()` deja uno abierto a propósito, porque lo que mide es «nombrar
     * el abierto no abre el cerrado». Aquí hace falta lo contrario: que en el año
     * en curso no quede nada abierto, para que un 200 solo pueda venir del año de
     * la fila y no del del usuario.
     */
    private function escenarioConElAnioEnCursoCerrado(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        return (object) ['token' => $token, 'year_id' => (int) $suyo->year_id];
    }

    /** Una unidad viva de otro año, con su periodo. El seed tiene 166 en 2024. */
    private function unidadDeOtroAnio(int $yearIdEnCurso): ?object
    {
        return DB::selectOne('SELECT u.id, u.definicion, u.porcentaje, p.id AS periodo_id, y.year
            FROM unidades u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            INNER JOIN years y ON y.id = p.year_id AND y.deleted_at IS NULL
            WHERE p.year_id <> ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1', [$yearIdEnCurso]);
    }

    /**
     * El candado es por (año, periodo) y no mira si el año es el actual.
     *
     * Cada año tiene sus cuatro periodos con su propio interruptor: el 1 de un
     * año puede estar bloqueado y el 1 del siguiente abierto. Como
     * `aplicarBanderasDelPeriodo()` lee el periodo **por id** y no por número,
     * eso ya funciona — este test lo fija.
     *
     * Se le preguntó derecho a Joseth el 21 ago 2026 si era lo que quería, con la
     * alternativa de exigir además `years.actual`, y contestó que **manda solo el
     * interruptor**: un colegio cierra un año pasado bloqueando sus periodos, que
     * es la herramienta que ya tiene, y si dejó uno abierto es porque quiere
     * poder corregir ahí.
     *
     * Por eso existe este test y no solo el párrafo: añadir un `years.actual` al
     * lado del interruptor parece prudencia y apagaría las correcciones de enero
     * en los dieciséis colegios. Ver 05 §27.3.
     */
    public function test_un_periodo_abierto_de_un_ano_pasado_deja_escribir_aunque_el_ano_en_curso_este_cerrado(): void
    {
        $e = $this->escenarioConElAnioEnCursoCerrado();
        $unidad = $this->unidadDeOtroAnio($e->year_id);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades fuera del año en curso.');
        }

        DB::table('periodos')->where('id', $unidad->periodo_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$unidad->id, [
            'definicion' => 'corregida en un año pasado',
            'porcentaje' => $unidad->porcentaje,
        ])->assertStatus(200);

        $this->assertSame('corregida en un año pasado',
            DB::table('unidades')->where('id', $unidad->id)->value('definicion'),
            'El periodo abierto del año pasado no dejó escribir, y debía.');
    }

    /**
     * La otra mitad, y es la que hace que la de arriba mida algo.
     *
     * Mismo profesor, misma unidad de un año pasado, y lo único que cambia es el
     * interruptor de **ese** periodo. Sin este caso, el de arriba pasaría también
     * si el candado no mirara nada.
     */
    public function test_y_ese_mismo_periodo_del_ano_pasado_cerrado_no_deja(): void
    {
        $e = $this->escenarioConElAnioEnCursoCerrado();
        $unidad = $this->unidadDeOtroAnio($e->year_id);

        if ($unidad === null) {
            $this->markTestSkipped('El seed no tiene unidades fuera del año en curso.');
        }

        DB::table('periodos')->where('id', $unidad->periodo_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$unidad->id, [
            'definicion' => 'corregida en un año pasado',
            'porcentaje' => $unidad->porcentaje,
        ])->assertStatus(400);

        $this->assertSame($unidad->definicion,
            DB::table('unidades')->where('id', $unidad->id)->value('definicion'));
    }

    /**
     * La nivelación de otro año se gobierna con el candado del año EN CURSO, y
     * eso se queda así.
     *
     * `recuperacion_final` no tiene `periodo_id` —guarda alumno, asignatura,
     * `year` y nota—, así que `todosLosDelAnio($user)` pide los periodos del año
     * del usuario. Con una fila de otro año, el permiso que se comprueba no es el
     * de esa fila: con 2024 cerrado y el año en curso abierto, se toca 2024.
     *
     * Preguntado a Joseth el 21 ago 2026 junto con la de arriba, y contestó
     * **dejarlo como está**: el front manda `{rf_id, nota}` desde la pantalla del
     * año en curso, así que ninguna pantalla llega aquí con un `rf_id` viejo.
     *
     * Este test fija el hueco a propósito, como el de `UniformesTest` antes de la
     * §27.1.1: **el día que se decida cerrarlo, este test falla, y ése es su
     * trabajo** — obliga a venir a leer por qué se dejó abierto antes de cambiarlo.
     */
    public function test_la_nivelacion_de_otro_ano_la_gobierna_el_ano_en_curso(): void
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $suyo = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $otro = DB::selectOne('SELECT id, year FROM years
            WHERE id <> ? AND deleted_at IS NULL ORDER BY year DESC LIMIT 1', [$suyo->year_id]);

        $this->assertNotNull($otro, 'El seed necesita más de un año para medir esto.');

        // El año en curso abierto de par en par; el otro, cerrado del todo.
        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_nivelar' => 1]);
        DB::table('periodos')->where('year_id', $otro->id)
            ->update(['profes_pueden_nivelar' => 0]);

        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');
        $asignatura = DB::selectOne('SELECT asig.id FROM asignaturas asig
            INNER JOIN grupos g ON g.id = asig.grupo_id AND g.year_id = ?
            WHERE asig.deleted_at IS NULL ORDER BY asig.id LIMIT 1', [$otro->id]);

        $this->assertNotNull($alumno, 'El seed necesita un alumno.');
        $this->assertNotNull($asignatura, 'El seed necesita una asignatura en el año pasado.');

        // La fila es del año pasado: es su columna `year` la que lo dice.
        $rfId = DB::table('recuperacion_final')->insertGetId([
            'alumno_id' => $alumno->id,
            'asignatura_id' => $asignatura->id,
            'year' => $otro->year,
            'nota' => 3,
        ]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rfId,
            'nota' => 1,
        ])->assertStatus(200);

        $this->assertEquals(1,
            DB::table('recuperacion_final')->where('id', $rfId)->value('nota'),
            'Si esto falla, alguien cerró el hueco: ve a 05 §27.3 antes de tocarlo.');
    }
}
