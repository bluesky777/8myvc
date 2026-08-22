<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las seis rutas de ausencias que nadie había mirado.
 *
 * Las ausencias salen en el boletín y en el observador, y el módulo ya había dado
 * un hallazgo por otro lado: la §25, donde un alumno sacaba las de todo el colegio
 * por el lector de tardanzas. Estas seis son las de la aplicación normal.
 *
 * Lo que sale de aquí y **necesita una decisión de Joseth** está en la §40:
 * crear una ausencia no comprueba el periodo y editarla sí.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class AusenciasTest extends CasoDeContrato
{
    /** Un profesor, su token, su periodo y una asignatura con alumnos. */
    private function contexto(): object
    {
        $prof = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($prof->username);

        $periodo = DB::selectOne('SELECT p.id, p.numero, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$prof->id]);

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS")
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$periodo->year_id]);

        $this->assertNotNull($asignatura, 'El seed necesita una asignatura con alumnos en el año del profesor.');

        $alumno = DB::selectOne('SELECT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
            ORDER BY m.alumno_id LIMIT 1', [$asignatura->grupo_id]);

        return (object) [
            'token' => $token,
            'user_id' => $prof->id,
            'periodo' => $periodo,
            'asignatura' => $asignatura,
            'alumno_id' => $alumno->alumno_id,
        ];
    }

    /** Anotar una ausencia la escribe, y en el periodo del profesor. */
    public function test_anotar_una_ausencia_la_escribe_en_su_periodo(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 2,
            'fecha_hora' => '2026-08-21 07:00:00',
        ]);

        // 201 y no 200: la respuesta es un modelo Eloquent recién creado y
        // Laravel lo pone solo. Es el mismo caso que `opciones/add-opcion` de la
        // §27.2 y el contrario que `years/store`, que vuelve a buscar el modelo
        // antes de devolverlo y por eso responde 200. Se fija el número que los
        // clientes reciben hoy.
        $r->assertStatus(201);

        $fila = DB::table('ausencias')->where('id', $r->json('id'))->first();

        $this->assertNotNull($fila, 'La ausencia no llegó a escribirse.');
        $this->assertEquals($c->periodo->id, $fila->periodo_id,
            'La ausencia no se escribió en el periodo del profesor.');
        $this->assertSame('ausencia', $fila->tipo,
            'El tipo se deduce de la cantidad y dejó de deducirse.');
    }

    /** Y una tardanza es una fila de tipo `tardanza` con cantidad 1. */
    public function test_agregar_una_tardanza_escribe_una_de_tipo_tardanza(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/agregar-tardanza', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'now' => '2026-08-21 07:10:00',
        ]);

        $r->assertStatus(201);

        $fila = DB::table('ausencias')->where('id', $r->json('id'))->first();

        $this->assertSame('tardanza', $fila->tipo);
        $this->assertEquals(1, $fila->cantidad_tardanza);
    }

    /**
     * El listado por asignatura trae a los alumnos con lo suyo, y nada de más.
     *
     * Cuelga de cada alumno un `userData`, y lo que importa comprobar de él es lo
     * que **no** lleva: es una lista de columnas nombrada, no un `SELECT *`, así
     * que no arrastra credenciales. Se fija para que siga siéndolo.
     */
    public function test_el_listado_por_asignatura_no_trae_credenciales(): void
    {
        $c = $this->contexto();

        $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
            'fecha_hora' => '2026-08-21 08:00:00',
        ])->assertStatus(201);

        $r = $this->withToken($c->token)
            ->getJson('/api/ausencias/detailed/'.$c->asignatura->id);

        $r->assertStatus(200);
        $this->assertStringNotContainsString('$2y$', (string) $r->getContent(),
            'El listado de ausencias devuelve un hash.');
        $this->assertStringNotContainsString('"password"', (string) $r->getContent());
    }

    /**
     * Editar, cambiar de tipo y borrar tampoco los cierra el periodo.
     *
     * Este test estaba al revés: fijaba que las tres respetaban el periodo
     * cerrado, porque era lo que hacían —eran tres de las 26 llamadas de la §27—.
     * Joseth decidió el 21 ago 2026 que **la asistencia no la bloquea el
     * interruptor del periodo**, y al preguntarle por esta mitad contestó que
     * también: excusar una falta cuando el alumno trae la excusa es el mismo
     * trabajo de asistencia, no calificar. Las tres llamadas se retiraron.
     *
     * Lo que el test comprueba ahora es que **la corrección llega a la fila**, no
     * solo que responda 200: lo que importa es que el profesor pueda arreglar una
     * ausencia mal puesta con el boletín ya cerrado. Ver 05 §40.
     */
    public function test_con_el_periodo_cerrado_se_sigue_corrigiendo_y_borrando(): void
    {
        $c = $this->contexto();

        $r = $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 3,
            'fecha_hora' => '2026-08-21 09:00:00',
        ]);
        $ausenciaId = $r->json('id');

        DB::table('periodos')->where('year_id', $c->periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0, 'profes_pueden_nivelar' => 0]);

        $this->withToken($c->token)->putJson('/api/ausencias/cambiar-tipo-ausencia', [
            'ausencia_id' => $ausenciaId,
            'new_tipo' => 'tardanza',
        ])->assertStatus(200);

        $this->assertSame('tardanza',
            DB::table('ausencias')->where('id', $ausenciaId)->value('tipo'),
            'La corrección no llegó a la fila con el periodo cerrado.');

        $this->withToken($c->token)
            ->deleteJson('/api/ausencias/destroy/'.$ausenciaId)
            ->assertStatus(200);

        $this->assertNotNull(DB::table('ausencias')->where('id', $ausenciaId)->value('deleted_at'),
            'Borrar la ausencia dejó de funcionar con el periodo cerrado.');
    }

    /**
     * Con el periodo cerrado **se sigue pudiendo anotar**, y ahora es una decisión.
     *
     * `store` y `agregar-tardanza` no llaman a `pueden_editar_notas()` y sus tres
     * hermanas del mismo controlador sí. Se le preguntó a Joseth si era un olvido
     * y contestó que no: **«que poner asistencias no se bloquee al bloquear
     * periodos»** (21 ago 2026). Pasar asistencia es trabajo de todos los días y
     * el interruptor se llama `profes_pueden_editar_notas`.
     *
     * Así que este test deja de afirmar un comportamiento pendiente y pasa a
     * afirmar uno **querido**: si algún día alguien le pone el candado a estas dos
     * rutas «por coherencia con las otras tres», esto falla y le cuenta por qué no.
     * Ver 05 §40.
     */
    public function test_con_el_periodo_cerrado_todavia_se_puede_anotar(): void
    {
        $c = $this->contexto();

        DB::table('periodos')->where('year_id', $c->periodo->year_id)
            ->update(['profes_pueden_editar_notas' => 0]);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c;

        $this->withToken($c->token)->postJson('/api/ausencias/store', [
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $c->asignatura->id,
            'cantidad_ausencia' => 1,
            'fecha_hora' => '2026-08-21 10:00:00',
        ])->assertStatus(201);

        $this->assertSame($antes + 1, DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c,
            'Anotar asistencia dejó de funcionar con el periodo cerrado, y Joseth '
            .'decidió el 21 ago 2026 que no debía bloquearse.');
    }

    /**
     * Corregir el día de una falta **ajena** se puede, y ahora es una decisión.
     *
     * `putGuardarCambiosAusencia` tenía una comprobación de permisos calculada y
     * tirada a la basura —`Role::isCoorDisciplinario()` seguido de un `if` con el
     * cuerpo vacío—, lo mismo que `deleteDestroy`. `myvc_front` la vio en la fase
     * 11 y la dejó apuntada por ser del backend; nadie volvió.
     *
     * Se le preguntó a Joseth el 22 ago 2026 con el alcance medido delante y
     * contestó que **se queda abierto**, en la misma línea que el interruptor del
     * periodo: corregir una falta mal puesta es trabajo de asistencia. El cálculo
     * muerto se retiró y el porqué quedó escrito en el controlador.
     *
     * **Este test existe para el día que alguien quiera cerrarlo.** Si se rellena
     * el `if`, esto falla y le cuenta qué se lleva por delante: el menú de
     * AngularJS enseña «Asistencias» a `profesor`, y `myvc_flutter` —una sola app
     * para los dieciséis colegios— borra y corrige desde la pantalla de
     * asistencia del profesor sin mirar ningún rol. La app no se publica el mismo
     * día que el backend.
     *
     * La falta que se corrige es de **otro grupo**, y a propósito: lo que está
     * abierto no es solo «cualquier profesor», es «cualquier profesor sobre
     * cualquier falta del colegio». Quien cierre esto tiene que decidir las dos.
     */
    public function test_corregir_el_dia_de_una_falta_ajena_se_puede_y_queda_firmado(): void
    {
        $c = $this->contexto();
        $ajeno = $this->grupoAjenoDelMismoAnio((int) $c->periodo->year_id);

        $ausenciaId = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $ajeno->asignatura_id,
            'periodo_id' => $c->periodo->id,
            'cantidad_ausencia' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withToken($c->token)->putJson('/api/ausencias/guardar-cambios-ausencia', [
            'ausencia_id' => $ausenciaId,
            'fecha_hora' => '2026-08-19 07:00:00',
        ]);

        $r->assertStatus(200);

        $fila = DB::table('ausencias')->where('id', $ausenciaId)->first();

        $this->assertStringStartsWith('2026-08-19', (string) $fila->fecha_hora,
            'La corrección no llegó a la fila de un grupo ajeno.');
        $this->assertNotNull($fila->updated_by,
            'Corregir una falta dejó de anotar quién la corrigió.');
    }

    /**
     * Borrar una falta **la firma**, y hasta el 22 ago 2026 no la firmaba.
     *
     * De las tres rutas que borran una ausencia, dos —la del lector y la de
     * `asistencias/*`— ponían `deleted_by`; la de las pantallas web y la de
     * Flutter no ponía nada. En la copia de producción del 22 ago hay **5.689
     * ausencias borradas y 5.684 sin autor**, y las cinco firmadas son justo las
     * que pasaron por el lector.
     *
     * Es la contrapartida de la decisión de arriba: si borrar sigue abierto a
     * cualquiera del personal, el rastro es lo único que queda — y estaba en
     * blanco.
     *
     * Se comprueba sobre una falta de **otro grupo** por lo mismo que el test de
     * arriba, y se mira el valor y no solo que no sea nulo: `deleted_by` es de
     * `users`, no de `personas`, y confundirlos es la trampa de siempre en este
     * repo (`$user->user_id` contra `$user->persona_id`).
     */
    public function test_borrar_una_falta_ajena_anota_quien_fue(): void
    {
        $c = $this->contexto();
        $ajeno = $this->grupoAjenoDelMismoAnio((int) $c->periodo->year_id);

        $ausenciaId = DB::table('ausencias')->insertGetId([
            'alumno_id' => $c->alumno_id,
            'asignatura_id' => $ajeno->asignatura_id,
            'periodo_id' => $c->periodo->id,
            'cantidad_ausencia' => 1,
            'tipo' => 'ausencia',
            'fecha_hora' => '2026-08-22 07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($c->token)
            ->deleteJson('/api/ausencias/destroy/'.$ausenciaId)
            ->assertStatus(200);

        $fila = DB::table('ausencias')->where('id', $ausenciaId)->first();

        $this->assertNotNull($fila->deleted_at, 'La falta no se borró.');
        $this->assertSame((int) $c->user_id, (int) $fila->deleted_by,
            'Borrar una falta no anota quién fue, o anota el id equivocado.');
    }
}
