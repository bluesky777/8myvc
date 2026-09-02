<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El acta de la recuperación del año (A9,
 * [22-nivelaciones.md](../../docs/migracion/22-nivelaciones.md) §9).
 *
 * `recuperacion_final` ya hacía lo difícil —guardar la nota de la recuperación
 * aparte en vez de pisar la original, que es el único sitio del proyecto que lo
 * hacía— y le faltaba lo fácil: **cuándo, quién y con qué**. Aquí no hay endpoint
 * nuevo y es a propósito: la fila entera **es** la recuperación, así que cada
 * escritura es el acta, y no hay un «corregir» que distinguir de un «nivelar» como
 * en el indicador y en la definitiva.
 *
 * Por eso la mitad de estos casos comprueba lo de siempre: que el cliente que ya
 * llama **no cambia**, porque manda `{rf_id, nota}` y nada más.
 */
class ActaDeLaRecuperacionFinalTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /**
     * Una recuperación del año sobre la que trabajar, **fabricada si el seed no la
     * trae** — y hoy no la trae: `recuperacion_final` está vacía.
     *
     * Se fabrica **por la API** y no con un `INSERT` a mano, que es lo que la hace
     * valer: si el camino de crear se rompiera, estos casos no se saltarían
     * silenciosamente, fallarían. Y saltarlos era lo peor: cuatro de los siete
     * casos de este fichero se marcaban «skipped» y no medían nada, que es
     * exactamente la forma de tranquilizar sin comprobar contra la que avisa
     * `CLAUDE.md`.
     */
    private function unaRecuperacion(string $token): object
    {
        $fila = DB::selectOne('SELECT id, alumno_id, asignatura_id, year, nota FROM recuperacion_final ORDER BY id LIMIT 1');

        if ($fila !== null) {
            return $fila;
        }

        $donde = $this->alumnoYAsignatura();

        $r = $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'alumno_id' => $donde->alumno_id,
            'asignatura_id' => $donde->asignatura_id,
            'nota' => 30,
        ]);

        $r->assertStatus(200);

        $fila = DB::selectOne('SELECT id, alumno_id, asignatura_id, year, nota FROM recuperacion_final WHERE id = ?',
            [$r->json('id')]);

        $this->assertNotNull($fila, 'No se pudo fabricar la recuperación por la API.');

        return $fila;
    }

    private function filaDe(int $id): object
    {
        return DB::selectOne('SELECT nota, nivelada_at, nivelada_por, observacion, updated_by
            FROM recuperacion_final WHERE id = ?', [$id]);
    }

    /** Un alumno con su asignatura del año actual, para crear una recuperación. */
    private function alumnoYAsignatura(): object
    {
        $fila = DB::selectOne('SELECT m.alumno_id, a.id AS asignatura_id
            FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN ("MATR", "ASIS")
            ORDER BY m.alumno_id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un alumno matriculado en el año actual con asignatura.');

        return $fila;
    }

    /**
     * Registrar la recuperación del año deja el acta: **quién y cuándo**.
     *
     * Sin `nivelada_por`, la única pista de quién la registró era `updated_by`, que
     * dice **quién la tocó la última vez** — y no es lo mismo. Es la distinción que
     * hace que la constancia del art. 17 se pueda firmar.
     */
    public function test_crear_una_recuperacion_deja_quien_y_cuando(): void
    {
        $token = $this->tokenDeSuperusuario();
        $donde = $this->alumnoYAsignatura();

        $r = $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'alumno_id' => $donde->alumno_id,
            'asignatura_id' => $donde->asignatura_id,
            'nota' => 40,
            'observacion' => 'Plan de mejoramiento de fin de año',
        ]);

        $r->assertStatus(200)
            ->assertJsonPath('observacion', 'Plan de mejoramiento de fin de año');

        $this->assertNotNull($r->json('nivelada_at'), 'El acta sin fecha no es un acta (art. 16).');
        $this->assertNotNull($r->json('nivelada_por'), 'El acta sin responsable no es un acta.');

        $fila = $this->filaDe((int) $r->json('id'));

        $this->assertNotNull($fila->nivelada_por, 'Lo devuelto tiene que ser lo escrito.');
        $this->assertSame('Plan de mejoramiento de fin de año', $fila->observacion);
    }

    /** La respuesta lleva las diez columnas nombradas, con las tres del acta dentro. */
    public function test_la_respuesta_al_crear_trae_las_tres_del_acta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $donde = $this->alumnoYAsignatura();

        $r = $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'alumno_id' => $donde->alumno_id,
            'asignatura_id' => $donde->asignatura_id,
            'nota' => 40,
        ]);

        $r->assertStatus(200);

        $claves = array_keys($r->json());
        sort($claves);

        $this->assertSame([
            'alumno_id', 'asignatura_id', 'created_at', 'id', 'nivelada_at', 'nivelada_por',
            'nota', 'observacion', 'updated_at', 'updated_by', 'year',
        ], $claves, 'La forma de la respuesta cambió: las columnas van nombradas, no por asterisco.');
    }

    /** Editar una recuperación existente también actualiza el acta. */
    public function test_editar_una_recuperacion_actualiza_el_acta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $rf = $this->unaRecuperacion($token);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 40,
            'observacion' => 'Segunda sustentación',
        ])->assertStatus(200);

        $fila = $this->filaDe((int) $rf->id);

        $this->assertEquals(40, $fila->nota);
        $this->assertNotNull($fila->nivelada_at);
        $this->assertNotNull($fila->nivelada_por);
        $this->assertSame('Segunda sustentación', $fila->observacion);
    }

    /**
     * **El cliente de hoy no cambia.** `DefinitivasPeriodosCtrl` manda
     * `{rf_id, nota}` y nada más: tiene que seguir guardando igual, con la fecha
     * del servidor y sin observación.
     */
    public function test_el_cliente_que_manda_solo_rf_id_y_nota_sigue_funcionando(): void
    {
        $token = $this->tokenDeSuperusuario();
        $rf = $this->unaRecuperacion($token);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 35,
        ])->assertStatus(200);

        $fila = $this->filaDe((int) $rf->id);

        $this->assertEquals(35, $fila->nota, 'El camino de siempre dejó de guardar la nota.');
        $this->assertNotNull($fila->nivelada_at, 'Sin fecha del servidor, el acta nace coja.');
        $this->assertNull($fila->observacion, 'Una observación que nadie mandó no se inventa.');
    }

    /** Una observación de más de 255 es 422, y no escribe. */
    public function test_una_observacion_demasiado_larga_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $rf = $this->unaRecuperacion($token);

        $antes = $this->filaDe((int) $rf->id);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 40,
            'observacion' => str_repeat('a', 256),
        ])->assertStatus(422);

        $this->assertEquals($antes->nota, $this->filaDe((int) $rf->id)->nota, 'Un 422 no escribe nada.');
    }

    /** Y una fecha futura tampoco: un acta con fecha de mañana no es un acta. */
    public function test_una_fecha_futura_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $rf = $this->unaRecuperacion($token);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'rf_id' => $rf->id,
            'nota' => 40,
            'fecha' => '2030-01-01',
        ])->assertStatus(422);
    }

    /**
     * **`year` sigue siendo el número y no el id**, que es lo que esta tarea deja
     * fuera a propósito (§7 del plan).
     *
     * El caso existe porque el cambio es tentador y silencioso: `recuperacion_final`
     * es la única tabla del dominio que guarda el año así, y quien la «arregle» de
     * paso rompe `PeriodoDeLaFila::todosLosDelAnio`, que es de donde sale que esta
     * escritura exija **todos** los periodos abiertos y no uno.
     */
    public function test_el_year_sigue_siendo_el_numero(): void
    {
        $token = $this->tokenDeSuperusuario();
        $donde = $this->alumnoYAsignatura();

        $r = $this->withToken($token)->putJson('/api/definitivas_periodos/update-recuperacion', [
            'alumno_id' => $donde->alumno_id,
            'asignatura_id' => $donde->asignatura_id,
            'nota' => 40,
        ]);

        $r->assertStatus(200);

        $anios = DB::select('SELECT id, year FROM years WHERE deleted_at IS NULL');
        $numeros = array_map(fn ($y) => (int) $y->year, $anios);

        $this->assertContains((int) $r->json('year'), $numeros,
            '`recuperacion_final.year` dejó de ser el número del año. Es un refactor de permisos '.
            'decidido en `PeriodoDeLaFila`, no un efecto secundario del acta.');
    }
}
