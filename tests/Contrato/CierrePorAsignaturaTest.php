<?php

namespace Tests\Contrato;

use App\Support\CierreDeAsignatura;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * **El cierre por asignatura** (fase 2 de `myvc_front/PLAN-CIERRE-DE-PERIODO.md`).
 *
 * Tres cosas y en este orden, porque es el orden en que se rompen:
 *
 * 1. **Nace neutro**: sin filas en `cierres_asignatura` el docente escribe como
 *    siempre.
 * 2. **Cerrar muerde sólo a esa asignatura**: la de al lado, del mismo docente y
 *    el mismo periodo, sigue abierta — es todo el punto frente al candado del
 *    periodo.
 * 3. **Reabrir es de coordinación, con fecha, y la rendija se cierra sola.** Y desde
 *    el 24 sep 2026, también de su docente, sin fecha, mientras el periodo esté
 *    abierto; con el periodo cerrado, no.
 *
 * Y las puertas: cada familia que escribe en una asignatura (nota, lote,
 * indicador, unidad, falta, frases) se prueba con la asignatura cerrada.
 */
class CierrePorAsignaturaTest extends CasoDeContrato
{
    /**
     * Un profesor con DOS asignaturas con notas en su periodo, el periodo abierto
     * y su token. El periodo se lee DESPUÉS del token: `Services\Login` lo reescribe.
     */
    private function escenario(): object
    {
        $candidatos = DB::select('SELECT u.id, u.username, pr.id AS profesor_id FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id');

        foreach ($candidatos as $c) {
            $token = $this->tokenDe($c->username);
            $periodoId = (int) DB::table('users')->where('id', $c->id)->value('periodo_id');

            $asignaturas = DB::select('SELECT a.id, MIN(n.id) AS nota_id
                FROM asignaturas a
                INNER JOIN unidades un ON un.asignatura_id = a.id AND un.periodo_id = ? AND un.deleted_at IS NULL
                INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
                INNER JOIN notas n ON n.subunidad_id = s.id AND n.deleted_at IS NULL AND n.nota IS NOT NULL
                WHERE a.profesor_id = ? AND a.deleted_at IS NULL
                GROUP BY a.id ORDER BY a.id LIMIT 2', [$periodoId, $c->profesor_id]);

            if (count($asignaturas) === 2) {
                DB::table('periodos')->where('id', $periodoId)
                    ->update(['profes_pueden_editar_notas' => 1, 'profes_pueden_nivelar' => 1]);
                DB::table('cierres_asignatura')->where('periodo_id', $periodoId)->delete();

                return (object) [
                    'token' => $token,
                    'user_id' => (int) $c->id,
                    'profesor_id' => (int) $c->profesor_id,
                    'periodo_id' => $periodoId,
                    'cerrada' => (int) $asignaturas[0]->id,
                    'nota' => (int) $asignaturas[0]->nota_id,
                    'otra' => (int) $asignaturas[1]->id,
                    'nota_otra' => (int) $asignaturas[1]->nota_id,
                ];
            }
        }

        $this->markTestSkipped('El seed no tiene un profesor con dos asignaturas calificadas en su periodo.');
    }

    private function tokenDeCoordinacion(): string
    {
        // `usuarioDeTipo('Usuario')` es superusuario (ver `usuarioLlanoDelPersonal`),
        // que es de los que reabren.
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    private function cerrar(object $e): void
    {
        $this->withToken($e->token)->putJson('/api/cierres-asignatura/cerrar', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
        ])->assertStatus(200)->assertJsonPath('cerrada', true);
    }

    private function valorDe(int $notaId)
    {
        return DB::table('notas')->where('id', $notaId)->value('nota');
    }

    public function test_sin_filas_el_docente_escribe_como_siempre(): void
    {
        $e = $this->escenario();

        $this->assertSame(0, DB::table('cierres_asignatura')->count(),
            'La tabla debería nacer vacía en el seed: si no, el colegio que actualiza vería un cambio.');

        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ])->assertStatus(200);
    }

    public function test_cerrar_muerde_solo_a_esa_asignatura(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $r = $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ]);
        $r->assertStatus(400);
        $this->assertSame(User::ASIGNATURA_CERRADA, $r->json('message'));

        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota_otra, [
            'nota' => $this->valorDe($e->nota_otra),
        ])->assertStatus(200);
    }

    public function test_el_lote_de_notas_tampoco_pasa(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $this->withToken($e->token)->putJson('/api/notas/lote', [
            'notas' => [['id' => $e->nota, 'nota' => $this->valorDe($e->nota)]],
        ])->assertStatus(400);
    }

    public function test_borrar_una_nota_de_la_asignatura_cerrada_no_pasa(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $this->withToken($e->token)->deleteJson('/api/notas/destroy/'.$e->nota)->assertStatus(400);
        $this->assertNotNull(DB::table('notas')->where('id', $e->nota)->first());
    }

    public function test_indicadores_y_unidades_de_la_asignatura_cerrada_no_se_tocan(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $unidad = DB::selectOne('SELECT id, definicion, porcentaje FROM unidades
            WHERE asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$e->cerrada, $e->periodo_id]);

        $this->withToken($e->token)->postJson('/api/subunidades', [
            'unidad_id' => $unidad->id, 'definicion' => 'nueva con la asignatura cerrada', 'porcentaje' => 1,
        ])->assertStatus(400);

        $this->withToken($e->token)->putJson('/api/unidades/update/'.$unidad->id, [
            'definicion' => 'reescrita con la asignatura cerrada', 'porcentaje' => $unidad->porcentaje,
        ])->assertStatus(400);

        $this->withToken($e->token)->postJson('/api/unidades', [
            'asignatura_id' => $e->cerrada, 'definicion' => 'unidad nueva', 'porcentaje' => 1,
        ])->assertStatus(400);

        $this->assertSame($unidad->definicion, DB::table('unidades')->where('id', $unidad->id)->value('definicion'));
    }

    public function test_la_falta_de_asistencia_en_la_asignatura_cerrada_no_pasa_y_la_de_al_lado_si(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $alumno = DB::selectOne('SELECT n.alumno_id FROM notas n WHERE n.id = ?', [$e->nota]);

        $r = $this->withToken($e->token)->postJson('/api/ausencias/agregar-ausencia', [
            'alumno_id' => $alumno->alumno_id, 'asignatura_id' => $e->cerrada, 'now' => '2026-09-24 08:00:00',
        ]);
        $r->assertStatus(400);
        $this->assertSame(User::ASIGNATURA_CERRADA, $r->json('message'));

        $this->withToken($e->token)->postJson('/api/ausencias/agregar-ausencia', [
            'alumno_id' => $alumno->alumno_id, 'asignatura_id' => $e->otra, 'now' => '2026-09-24 08:00:00',
        ])->assertSuccessful();
    }

    public function test_las_frases_del_grupo_dicen_que_no_se_puede_escribir(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $r = $this->withToken($e->token)->getJson('/api/frases_asignatura/grupo/'.$e->cerrada
            .'?periodo_id='.$e->periodo_id);

        if ($r->status() !== 200) {
            $this->markTestSkipped('frases_asignatura/grupo no contesta 200 a este profesor: '.$r->status());
        }

        $this->assertTrue($r->json('asignatura_cerrada'));
        $this->assertFalse($r->json('puede_escribir'));
    }

    public function test_coordinacion_sigue_escribiendo_en_la_asignatura_cerrada(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $this->assertTrue(User::permiteEditarNotas(
            (object) ['tipo' => 'Usuario', 'is_superuser' => 1, 'year_id' => null, 'numero_periodo' => null],
            $e->periodo_id, $e->cerrada
        ));
    }

    public function test_cerrar_la_asignatura_no_cierra_la_nivelacion(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $profesor = (object) ['tipo' => 'Profesor', 'is_superuser' => 0];

        $this->assertTrue(User::puedeNivelar($profesor, $e->periodo_id),
            'Cerrar la asignatura le quitó la nivelación: el diálogo promete lo contrario.');
    }

    public function test_el_docente_no_cierra_ni_reabre_la_de_otro(): void
    {
        $e = $this->escenario();

        $ajena = DB::selectOne('SELECT a.id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
            WHERE a.deleted_at IS NULL AND a.profesor_id IS NOT NULL AND a.profesor_id <> ?
            ORDER BY a.id LIMIT 1', [$e->periodo_id, $e->profesor_id]);

        $this->withToken($e->token)->putJson('/api/cierres-asignatura/cerrar', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $ajena->id,
        ])->assertStatus(403);

        // La cierra coordinación, y el docente de al lado no puede reabrírsela.
        $this->withToken($this->tokenDeCoordinacion())->putJson('/api/cierres-asignatura/cerrar', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $ajena->id,
        ])->assertStatus(200);

        $this->withToken($e->token)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $ajena->id,
        ])->assertStatus(403);
    }

    /**
     * Joseth, 24 sep 2026: «el docente puede volver a abrirla si no se ha cerrado el
     * periodo». Sin fecha: queda abierta hasta que la vuelva a cerrar, y la fila no se
     * borra — quién la cerró sigue ahí.
     */
    public function test_el_docente_reabre_la_suya_con_el_periodo_abierto_sin_fecha(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $this->withToken($e->token)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
        ])->assertStatus(200)
            ->assertJsonPath('estado', 'reabierta')
            ->assertJsonPath('cerrada', false)
            ->assertJsonPath('reabierta_hasta', null);

        $fila = DB::table('cierres_asignatura')->where('periodo_id', $e->periodo_id)
            ->where('asignatura_id', $e->cerrada)->first();
        $this->assertSame($e->user_id, (int) $fila->cerrada_por, 'Reabrir borró quién la cerró.');
        $this->assertSame($e->user_id, (int) $fila->reabierta_por);
        $this->assertNotNull($fila->reabierta_at);

        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ])->assertStatus(200);

        // Sin plazo no es «para siempre»: la vuelve a cerrar y muerde otra vez.
        $this->cerrar($e);
        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ])->assertStatus(400);
    }

    public function test_con_el_periodo_cerrado_el_docente_no_reabre_la_suya(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        DB::table('periodos')->where('id', $e->periodo_id)->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($e->token)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
            'hasta' => CierreDeAsignatura::ahora()->addDay()->toDateTimeString(), 'motivo' => 'me reabro yo',
        ])->assertStatus(403);

        $this->assertSame('cerrada', DB::table('cierres_asignatura')->where('periodo_id', $e->periodo_id)
            ->where('asignatura_id', $e->cerrada)->value('estado'));
    }

    public function test_la_rendija_abre_y_se_cierra_sola_al_vencer(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);
        $coordinacion = $this->tokenDeCoordinacion();

        $this->withToken($coordinacion)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
            'hasta' => CierreDeAsignatura::ahora()->addDay()->format('Y-m-d H:i'),
            'motivo' => 'Incapacidad médica del 18 al 22',
        ])->assertStatus(200)->assertJsonPath('estado', 'reabierta')->assertJsonPath('cerrada', false);

        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ])->assertStatus(200);

        // Vence: nadie escribe nada y vuelve a estar cerrada.
        DB::table('cierres_asignatura')->where('periodo_id', $e->periodo_id)->where('asignatura_id', $e->cerrada)
            ->update(['reabierta_hasta' => CierreDeAsignatura::ahora()->subMinute()->toDateTimeString()]);

        $this->withToken($e->token)->putJson('/api/notas/update/'.$e->nota, [
            'nota' => $this->valorDe($e->nota),
        ])->assertStatus(400);

        $this->assertSame('Incapacidad médica del 18 al 22', DB::table('cierres_asignatura')
            ->where('periodo_id', $e->periodo_id)->where('asignatura_id', $e->cerrada)->value('motivo'),
            'El motivo de la reapertura es el rastro: no se pierde al vencer.');
    }

    public function test_reabrir_pide_fecha_futura_y_algo_cerrado(): void
    {
        $e = $this->escenario();
        $coordinacion = $this->tokenDeCoordinacion();
        $futuro = CierreDeAsignatura::ahora()->addDay()->toDateTimeString();

        $this->withToken($coordinacion)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada, 'hasta' => $futuro, 'motivo' => 'x',
        ])->assertStatus(422);

        $this->cerrar($e);

        $this->withToken($coordinacion)->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
            'hasta' => CierreDeAsignatura::ahora()->subHour()->toDateTimeString(), 'motivo' => 'tarde',
        ])->assertStatus(422);
    }

    /** El motivo es opcional: en blanco se reabre igual y queda en nulo. */
    public function test_reabrir_sin_motivo(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $this->withToken($this->tokenDeCoordinacion())->putJson('/api/cierres-asignatura/reabrir', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
            'hasta' => CierreDeAsignatura::ahora()->addDay()->toDateTimeString(), 'motivo' => '  ',
        ])->assertOk();

        $this->assertNull(DB::table('cierres_asignatura')
            ->where('periodo_id', $e->periodo_id)->where('asignatura_id', $e->cerrada)->value('motivo'));
    }

    public function test_con_bloquear_no_se_cierra_con_huecos(): void
    {
        $e = $this->escenario();

        DB::table('notas')->where('id', $e->nota)->update(['nota' => null]);
        DB::table('periodos')->where('id', $e->periodo_id)->update(['cierre_sin_calificar' => null]);
        $yearId = (int) DB::table('periodos')->where('id', $e->periodo_id)->value('year_id');
        DB::table('years')->where('id', $yearId)->update(['cierre_sin_calificar' => 'bloquear']);

        $this->withToken($e->token)->putJson('/api/cierres-asignatura/cerrar', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => $e->cerrada,
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('cierres_asignatura')->count());
    }

    public function test_el_tablero_le_ensena_al_docente_las_suyas_y_a_coordinacion_todas(): void
    {
        $e = $this->escenario();
        $this->cerrar($e);

        $suyo = $this->withToken($e->token)->getJson('/api/cierres-asignatura/periodo/'.$e->periodo_id)
            ->assertStatus(200);

        $this->assertFalse($suyo->json('puede_reabrir'), 'El de arriba es el de coordinación.');
        $cerradaEnElTablero = collect($suyo->json('asignaturas'))->firstWhere('asignatura_id', $e->cerrada);
        $this->assertTrue($cerradaEnElTablero['puede_reabrir'],
            'Con el periodo abierto el docente reabre la suya (24 sep 2026).');
        $profesores = array_unique(array_column($suyo->json('asignaturas'), 'profesor_id'));
        $this->assertSame([(int) $e->profesor_id], array_map('intval', array_values($profesores)));
        $this->assertSame(1, $suyo->json('resumen.cerradas'));

        $todo = $this->withToken($this->tokenDeCoordinacion())
            ->getJson('/api/cierres-asignatura/periodo/'.$e->periodo_id)->assertStatus(200);

        $this->assertTrue($todo->json('puede_reabrir'));
        $this->assertGreaterThan(count($suyo->json('asignaturas')), count($todo->json('asignaturas')));

        $una = $this->withToken($e->token)
            ->getJson('/api/cierres-asignatura/asignatura/'.$e->cerrada.'/'.$e->periodo_id)->assertStatus(200);

        $this->assertSame('cerrada', $una->json('estado'));
        $this->assertTrue($una->json('puede_cerrar'));
        $this->assertTrue($una->json('puede_reabrir'));
        $this->assertFalse($una->json('reabrir_pide_fecha'));
        $this->assertIsArray($una->json('faltan_por_indicador'));
    }

    public function test_un_periodo_o_una_asignatura_que_no_existen_son_404(): void
    {
        $e = $this->escenario();

        $this->withToken($e->token)->getJson('/api/cierres-asignatura/periodo/999999')->assertStatus(404);
        $this->withToken($e->token)->putJson('/api/cierres-asignatura/cerrar', [
            'periodo_id' => $e->periodo_id, 'asignatura_id' => 999999,
        ])->assertStatus(404);
    }
}
