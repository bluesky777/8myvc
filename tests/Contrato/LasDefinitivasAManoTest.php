<?php

namespace Tests\Contrato;

use App\Services\Nivelacion;
use App\Support\DefinitivasAMano;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Nivelar es sólo nivelar** — fase 3 del cierre de periodo
 * (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, decisión 3).
 *
 * `years.profes_pueden_cambiar_definitivas` parte lo que `periodos.profes_pueden_nivelar`
 * gobernaba junto: con 0, el docente sigue nivelando pero no teclea la definitiva a mano.
 * Los casos van por pares —el que pasa con 1 y el que no con 0, sobre la MISMA fila y el
 * mismo docente— para que el 403 sólo pueda venir de la columna.
 */
class LasDefinitivasAManoTest extends CasoDeContrato
{
    private const RUTA = '/api/years/definitivas-a-mano';

    #[Test]
    public function todos_los_anios_nacen_pudiendo(): void
    {
        $this->assertSame(0, DB::table('years')->where('profes_pueden_cambiar_definitivas', '!=', 1)->count(),
            'La columna tiene que nacer en 1: es lo que conserva el comportamiento de hoy.');
    }

    #[Test]
    public function con_la_politica_en_1_el_docente_cambia_la_definitiva_como_siempre(): void
    {
        $e = $this->escenario(pueden: 1);

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/toggle-manual', [
            'nf_id' => $e->nf_id, 'manual' => 1,
        ])->assertStatus(200);

        $this->assertSame(1, (int) DB::table('notas_finales')->where('id', $e->nf_id)->value('manual'));
    }

    #[Test]
    public function con_la_politica_en_0_el_docente_no_la_cambia_y_se_le_dice_por_que(): void
    {
        $e = $this->escenario(pueden: 0);

        foreach ([
            ['put', '/api/definitivas_periodos/toggle-manual', ['nf_id' => $e->nf_id, 'manual' => 1]],
            ['put', '/api/definitivas_periodos/toggle-recuperada', ['nf_id' => $e->nf_id, 'recuperada' => 1]],
            ['put', '/api/definitivas_periodos/update', ['nf_id' => $e->nf_id, 'nota' => 33]],
            ['delete', '/api/definitivas_periodos/destroy/'.$e->nf_id, []],
        ] as [$verbo, $ruta, $cuerpo]) {
            $r = $verbo === 'put'
                ? $this->withToken($e->token)->putJson($ruta, $cuerpo)
                : $this->withToken($e->token)->deleteJson($ruta);

            $r->assertStatus(403);
            $this->assertStringContainsString('a mano', (string) $r->json('message'), $ruta);
        }

        $fila = DB::table('notas_finales')->where('id', $e->nf_id)->first();
        $this->assertNotNull($fila, 'Contestó 403 y borró igual.');
        $this->assertSame(0, (int) $fila->manual, 'Contestó 403 y escribió igual.');
    }

    /** La otra mitad: con 0 se sigue NIVELANDO. Es para lo que existe la columna. */
    #[Test]
    public function con_la_politica_en_0_el_docente_sigue_nivelando(): void
    {
        $e = $this->escenario(pueden: 0);

        DB::update('UPDATE years SET regla_nivelacion = ?, nota_minima_aceptada = ? WHERE id = ?',
            [Nivelacion::TOPADA, '35', $e->year_id]);
        Nivelacion::olvidar();
        DB::update('UPDATE notas_finales SET nota = 28 WHERE id = ?', [$e->nf_id]);

        $this->withToken($e->token)->putJson('/api/definitivas_periodos/nivelar', [
            'nf_id' => $e->nf_id, 'nota_nivelacion' => 45,
        ])->assertStatus(200)->assertJsonPath('nota', 35);
    }

    /** Coordinación sigue corrigiendo: la política es de los docentes. */
    #[Test]
    public function con_la_politica_en_0_el_superusuario_que_no_es_docente_si_la_cambia(): void
    {
        $e = $this->escenario(pueden: 0);
        $super = DB::selectOne('SELECT username FROM users WHERE is_superuser = 1 AND tipo <> "Profesor"
            AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($super->username))->putJson('/api/definitivas_periodos/toggle-manual', [
            'nf_id' => $e->nf_id, 'manual' => 1,
        ])->assertStatus(200);
    }

    #[Test]
    public function un_docente_llano_del_personal_no_la_decide(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $yearId = $this->anioActual();

        $this->withToken($this->tokenDe($usuario->username))
            ->putJson(self::RUTA, ['year_id' => $yearId, 'pueden' => 0])
            ->assertStatus(403);

        $this->assertSame(1, (int) DB::table('years')->where('id', $yearId)->value('profes_pueden_cambiar_definitivas'));
    }

    #[Test]
    public function el_superusuario_la_decide_y_se_le_devuelve_lo_que_quedo(): void
    {
        $yearId = $this->anioActual();

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson(self::RUTA, ['year_id' => $yearId, 'pueden' => 0])
            ->assertStatus(200)
            ->assertExactJson(['year_id' => $yearId, 'profes_pueden_cambiar_definitivas' => false]);

        $this->assertSame(0, (int) DB::table('years')->where('id', $yearId)->value('profes_pueden_cambiar_definitivas'));
    }

    /** `"false"` no es «sí» aquí, como sí lo es en los interruptores hermanos. */
    #[Test]
    public function un_valor_que_no_es_0_ni_1_es_422_y_no_escribe(): void
    {
        $yearId = $this->anioActual();

        foreach (['false', 'no', 2, null] as $raro) {
            $this->withToken($this->tokenDelSuperusuario())
                ->putJson(self::RUTA, ['year_id' => $yearId, 'pueden' => $raro])
                ->assertStatus(422);
        }

        $this->assertSame(1, (int) DB::table('years')->where('id', $yearId)->value('profes_pueden_cambiar_definitivas'));
    }

    #[Test]
    public function el_generico_de_years_no_puede_escribir_la_columna(): void
    {
        $yearId = $this->anioActual();

        $this->withToken($this->tokenDelPersonalLlano())->putJson('/api/years/toggle-cambiar-valor', [
            'year_id' => $yearId, 'campo' => 'profes_pueden_cambiar_definitivas', 'valor' => 0,
        ])->assertStatus(422);

        $this->assertSame(1, (int) DB::table('years')->where('id', $yearId)->value('profes_pueden_cambiar_definitivas'));
    }

    /** Sin la columna —colegio con el código nuevo y la migración sin correr— deja. */
    #[Test]
    public function un_anio_que_no_existe_no_cierra_nada(): void
    {
        $this->assertTrue(DefinitivasAMano::permitidas([]));
        $this->assertTrue(DefinitivasAMano::permitidas([999999]));
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un docente, una definitiva de su periodo en sesión, la nivelación ABIERTA en ese
     * periodo —si no, el 400 de siempre taparía el caso— y la política del año puesta.
     */
    private function escenario(int $pueden): object
    {
        $profesor = DB::selectOne('SELECT u.id AS user_id, u.username FROM users u
            INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profesor, 'El seed necesita un Profesor activo.');

        $token = $this->tokenDe($profesor->username);
        $periodoId = DB::table('users')->where('id', $profesor->user_id)->value('periodo_id');

        $fila = DB::selectOne('SELECT nf.id AS nf_id, p.year_id FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            WHERE nf.periodo_id = ? ORDER BY nf.id LIMIT 1', [$periodoId]);

        $this->assertNotNull($fila, 'El seed no tiene definitivas en el periodo en sesión del Profesor.');

        DB::table('periodos')->where('id', $periodoId)->update(['profes_pueden_nivelar' => 1]);
        DB::table('years')->where('id', $fila->year_id)->update(['profes_pueden_cambiar_definitivas' => $pueden]);
        DB::table('notas_finales')->where('id', $fila->nf_id)->update(['manual' => 0, 'recuperada' => 0]);

        $fila->token = $token;

        return $fila;
    }

    private function tokenDelSuperusuario(): string
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $this->assertSame(1, (int) $usuario->is_superuser);

        return $this->tokenDe($usuario->username);
    }

    private function anioActual(): int
    {
        return (int) DB::table('years')->where('actual', 1)->whereNull('deleted_at')->value('id');
    }
}
