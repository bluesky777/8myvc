<?php

namespace Tests\Contrato;

use App\Services\Auditoria;
use App\Services\Nivelacion;
use Illuminate\Support\Facades\DB;

/**
 * `PUT definitivas_periodos/nivelar` — la nivelación de la definitiva del periodo
 * (A8, [22-nivelaciones.md](../../docs/migracion/22-nivelaciones.md) §6).
 *
 * Y su centinela: `definitivas_periodos/update` **no aprendió a nivelar**. Es el
 * mismo §6.1 del reparto por el segundo camino — `myvc_flutter` teclea la
 * definitiva a mano desde `DefinitivasApi.dart`, y una versión vieja convive con
 * este backend durante meses.
 */
class NivelarLaDefinitivaTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** Una definitiva con su periodo vivo, que es lo que se puede nivelar. */
    private function unaDefinitiva(): object
    {
        $fila = DB::selectOne('SELECT nf.id, nf.alumno_id, nf.periodo_id, p.year_id
            FROM notas_finales nf
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.deleted_at IS NULL
            ORDER BY nf.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita una definitiva con su periodo vivo.');

        return $fila;
    }

    private function conRegla(int $yearId, string $regla, int $minima): void
    {
        DB::update('UPDATE years SET regla_nivelacion = ?, nota_minima_aceptada = ? WHERE id = ?',
            [$regla, (string) $minima, $yearId]);

        Nivelacion::olvidar();
    }

    private function filaDe(int $id): object
    {
        return DB::selectOne('SELECT CAST(nota AS DOUBLE) AS nota, CAST(nota_original AS DOUBLE) AS nota_original,
            CAST(nota_nivelacion AS DOUBLE) AS nota_nivelacion, nivelada_at, nivelada_por, nivelacion_obs,
            recuperada, manual FROM notas_finales WHERE id = ?', [$id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // El centinela del camino que no cambia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Teclear la definitiva a mano **no nivela**, con la regla encendida.
     *
     * `putUpdate` marca `manual` —eso ya lo hacía— pero no puede tocar el acta ni
     * aplicar la regla: si lo hiciera, una definitiva tecleada desde el móvil se
     * guardaría topada, que es el §6.1 exacto por el segundo camino.
     */
    public function test_teclear_la_definitiva_a_mano_no_nivela(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->conRegla((int) $nf->year_id, Nivelacion::TOPADA, 35);

        $this->withToken($token)->putJson('/api/definitivas_periodos/update', [
            'nf_id' => $nf->id,
            'nota' => 48,
        ])->assertStatus(200);

        $fila = $this->filaDe((int) $nf->id);

        $this->assertEquals(48, $fila->nota, 'La regla se aplicó por el camino de teclear a mano.');
        $this->assertNull($fila->nota_original, 'Teclear a mano no escribe la valoración inicial.');
        $this->assertNull($fila->nota_nivelacion);
        $this->assertNull($fila->nivelada_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // El endpoint nuevo
    // ─────────────────────────────────────────────────────────────────────

    /** La respuesta, y lo que queda escrito detrás. */
    public function test_nivelar_la_definitiva_aplica_la_regla_y_deja_el_acta(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->conRegla((int) $nf->year_id, Nivelacion::TOPADA, 35);
        DB::update('UPDATE notas_finales SET nota = 28, recuperada = 0, manual = 0 WHERE id = ?', [$nf->id]);

        $r = $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar', [
            'nf_id' => $nf->id,
            'nota_nivelacion' => 45,
            'observacion' => 'Sustentación de la asignatura',
        ]);

        $r->assertStatus(200)
            ->assertJsonPath('nf_id', (int) $nf->id)
            ->assertJsonPath('nota', 35)
            ->assertJsonPath('nota_original', 28)
            ->assertJsonPath('nota_nivelacion', 45)
            ->assertJsonPath('nivelacion_obs', 'Sustentación de la asignatura')
            ->assertJsonPath('regla_aplicada.regla', 'topada')
            ->assertJsonPath('regla_aplicada.explicacion',
                'Regla del colegio: la nivelación se topa en la mínima aprobatoria (35). Queda 35.');

        $fila = $this->filaDe((int) $nf->id);

        $this->assertEquals(35, $fila->nota, 'Lo devuelto tiene que ser lo escrito.');
        $this->assertEquals(28, $fila->nota_original);
        $this->assertEquals(45, $fila->nota_nivelacion,
            'Sin esta columna, bajo `topada` la nivelación de la definitiva no queda en ninguna parte.');
        $this->assertNotNull($fila->nivelada_por, 'Una novedad sin responsable no es una novedad (art. 16).');
    }

    /**
     * **Nivelar la definitiva la marca `recuperada` y `manual`.**
     *
     * No es cosmético: es lo que la desengancha del recálculo. Sin esto, la
     * nivelación duraría hasta que alguien abriera la planilla y
     * `DefinitivasDeAsignatura` la pisara — sin error y sin que nadie tocara nada.
     */
    public function test_nivelar_la_definitiva_la_desengancha_del_recalculo(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        DB::update('UPDATE notas_finales SET nota = 28, recuperada = 0, manual = 0 WHERE id = ?', [$nf->id]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar', [
            'nf_id' => $nf->id,
            'nota_nivelacion' => 45,
        ])->assertStatus(200)
            ->assertJsonPath('recuperada', true)
            ->assertJsonPath('manual', true);

        $fila = $this->filaDe((int) $nf->id);

        $this->assertEquals(1, $fila->recuperada, '`recuperada` sigue queriendo decir «viene de una nivelación».');
        $this->assertEquals(1, $fila->manual, 'Sin `manual`, el próximo recálculo se lleva la nivelación por delante.');
    }

    /** Repetir sustituye la nivelación y **conserva** la valoración inicial. */
    public function test_nivelar_dos_veces_conserva_la_valoracion_inicial(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->conRegla((int) $nf->year_id, Nivelacion::REEMPLAZA, 35);
        DB::update('UPDATE notas_finales SET nota = 28 WHERE id = ?', [$nf->id]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 40])->assertStatus(200)->assertJsonPath('nota', 40);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 45])->assertStatus(200)
            ->assertJsonPath('nota', 45)
            ->assertJsonPath('nota_original', 28);

        $this->assertEquals(28, $this->filaDe((int) $nf->id)->nota_original,
            'La segunda nivelación pisó la original con la vigente, que ya era nivelada.');
    }

    /**
     * Con `mayor`, la original gana **con sus decimales intactos**.
     *
     * La regla decide en enteros —es la escala del colegio— pero la definitiva es
     * `DECIMAL(7,4)` porque la produce una suma ponderada. Si el resultado se
     * escribiera redondeado, nivelar por debajo **cambiaría la definitiva** de
     * 43,7500 a 44 sin que nadie lo pidiera.
     */
    public function test_con_mayor_la_definitiva_conserva_sus_decimales(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->conRegla((int) $nf->year_id, Nivelacion::MAYOR, 35);
        DB::update('UPDATE notas_finales SET nota = 43.7500 WHERE id = ?', [$nf->id]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 20])->assertStatus(200);

        $this->assertEquals(43.75, $this->filaDe((int) $nf->id)->nota,
            'Nivelar por debajo redondeó la definitiva: 43,75 se convirtió en 44 sin que nadie lo pidiera.');
    }

    /** La línea de auditoría es `nivelar` sobre `nota_final`, no `editar`. */
    public function test_la_nivelacion_de_la_definitiva_se_audita_como_nivelacion(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 40])->assertStatus(200);

        $linea = DB::selectOne('SELECT accion, resumen FROM auditoria
            WHERE entidad = "nota_final" AND entidad_id = ? ORDER BY id DESC LIMIT 1', [$nf->id]);

        $this->assertNotNull($linea, 'Nivelar la definitiva no dejó línea de auditoría.');
        $this->assertSame(Auditoria::NIVELAR, $linea->accion,
            'Una nivelación registrada como `editar` es un teclazo más: la §1.2 del plan es que no se confundan.');
    }

    /** Y el rastro viejo se sigue escribiendo, que es lo que lee el historial de la app. */
    public function test_el_rastro_viejo_de_bitacoras_sigue_escribiendose(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $antes = DB::table('bitacoras')->count();

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 40])->assertStatus(200);

        $this->assertSame($antes + 1, DB::table('bitacoras')->count(),
            'Sin la línea de `bitacoras`, la pantalla de historial de la app se queda vacía.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los errores, y ninguno escribe
    // ─────────────────────────────────────────────────────────────────────

    public function test_sin_nf_id_o_sin_nota_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar', ['nota_nivelacion' => 40])
            ->assertStatus(422);
        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar', ['nf_id' => $nf->id])
            ->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nf->id)->nota_original);
    }

    public function test_una_definitiva_que_no_existe_es_404(): void
    {
        $token = $this->tokenDeSuperusuario();
        $inexistente = (int) DB::selectOne('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM notas_finales')->id;

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $inexistente, 'nota_nivelacion' => 40])->assertStatus(404);
    }

    public function test_fuera_de_la_escala_es_422(): void
    {
        $token = $this->tokenDeSuperusuario();
        $nf = $this->unaDefinitiva();

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 5000])->assertStatus(422);

        $this->assertNull($this->filaDe((int) $nf->id)->nota_original);
    }

    /** 403 en el endpoint nuevo, mientras el guard viejo conserva su 400. */
    public function test_con_el_periodo_cerrado_es_403_y_no_escribe(): void
    {
        $nf = $this->unaDefinitiva();
        $token = $this->tokenDelPersonalLlano();

        DB::update('UPDATE periodos SET profes_pueden_nivelar = 0 WHERE id = ?', [$nf->periodo_id]);

        $this->withToken($token)->putJson('/api/definitivas_periodos/nivelar',
            ['nf_id' => $nf->id, 'nota_nivelacion' => 40])->assertStatus(403);

        $this->assertNull($this->filaDe((int) $nf->id)->nota_original, 'Un 403 no escribe nada.');
    }
}
