<?php

namespace Tests\Contrato;

use App\Models\Matricula;
use App\Support\FotoDeLaPlantilla;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * **Los pendientes de la segunda tanda** (`myvc_front/PLAN-COSAS-PENDIENTES.md` §5b): periodos,
 * plantilla sin propagar, disciplina, matrícula, datos de personas, configuración del año y
 * firmas. `PendientesTest` cubre los de la primera.
 *
 * Cada test fabrica su caso dentro de la transacción y comprueba las dos mitades: que sale
 * cuando falta algo, y que **se va cuando se arregla**. Un pendiente que no se va nunca es peor
 * que no tenerlo: enseña al colegio a cerrar el aviso sin leerlo.
 */
class PendientesSegundaTandaTest extends CasoDeContrato
{
    /**
     * El personal llano con **exactamente** los roles pedidos. Es el mismo usuario en todo el
     * test, así que cada llamada le quita los de la anterior: sin eso, «el Secretario no lo ve»
     * lo comprobaría un Secretario que sigue siendo Coord disciplinario.
     */
    private function tokenCon(string ...$roles): string
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        DB::table('role_user')->where('user_id', $usuario->id)->delete();

        foreach ($roles as $rol) {
            $rolId = (int) (DB::table('roles')->where('name', $rol)->whereNull('deleted_at')->value('id')
                ?? DB::table('roles')->insertGetId(['name' => $rol, 'created_at' => now(), 'updated_at' => now()]));
            DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $rolId]);
        }

        return $this->tokenDe($usuario->username);
    }

    private function tokenDelSuper(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    /** @return array<string, array<string, mixed>> Los pendientes visibles, por tipo. */
    private function mios(string $token): array
    {
        $r = $this->withToken($token)->getJson('/api/pendientes/mios')->assertStatus(200);

        return array_column($r->json('pendientes'), null, 'tipo');
    }

    private function anio(): int
    {
        return (int) DB::table('years')->where('actual', 1)->value('id');
    }

    /** Un alumno MATR del año actual, con su grupo. */
    private function alumno(): object
    {
        $a = DB::selectOne('SELECT m.id AS matricula_id, m.alumno_id, g.id AS grupo_id FROM matriculas m
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
            WHERE m.estado = "MATR" AND m.deleted_at IS NULL ORDER BY m.id LIMIT 1', [$this->anio()]);
        $this->assertNotNull($a, 'El seed no tiene un alumno matriculado en el año actual.');

        return $a;
    }

    /** Deja actual el periodo `numero` del año actual. */
    private function periodoActual(int $numero): int
    {
        DB::table('periodos')->where('year_id', $this->anio())->update(['actual' => 0]);
        DB::table('periodos')->where('year_id', $this->anio())->where('numero', $numero)->update(['actual' => 1]);
        $id = (int) DB::table('periodos')->where('year_id', $this->anio())->where('numero', $numero)->value('id');
        $this->assertGreaterThan(0, $id, "El seed no tiene periodo {$numero} en el año actual.");

        return $id;
    }

    // ── Periodos ───────────────────────────────────────────────────────────────────

    public function test_las_fechas_de_periodos_salen_a_quien_puede_ponerlas_y_se_van_al_ponerlas(): void
    {
        DB::table('periodos')->where('year_id', $this->anio())->update(['fecha_inicio' => null]);

        $this->assertArrayHasKey('fechas_de_periodos', $this->mios($this->tokenCon('Admin')));

        $hoy = Reloj::ahora()->startOfDay();
        DB::table('periodos')->where('year_id', $this->anio())->update([
            'fecha_inicio' => $hoy->copy()->subDays(30)->toDateString(),
            'fecha_fin' => $hoy->copy()->addDays(30)->toDateString(),
            'fecha_entrega_boletines' => $hoy->copy()->addDays(60)->toDateString(),
        ]);

        $this->assertArrayNotHasKey('fechas_de_periodos', $this->mios($this->tokenCon('Admin')));
    }

    public function test_un_secretario_no_ve_los_de_periodos(): void
    {
        DB::table('periodos')->where('year_id', $this->anio())->update(['fecha_inicio' => null]);

        $this->assertArrayNotHasKey('fechas_de_periodos', $this->mios($this->tokenCon('Secretario')));
    }

    public function test_con_tres_periodos_se_puede_silenciar_y_sin_ninguno_no(): void
    {
        $ids = DB::table('periodos')->where('year_id', $this->anio())->whereNull('deleted_at')->orderByDesc('numero')->pluck('id')->all();
        $this->assertGreaterThanOrEqual(4, count($ids), 'El seed necesita 4 periodos en el año actual.');
        DB::table('periodos')->where('id', $ids[0])->update(['deleted_at' => now()]);

        $p = $this->mios($this->tokenDelSuper())['periodos_faltan'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('silenciable', $p['insistencia']);

        DB::table('periodos')->where('year_id', $this->anio())->update(['deleted_at' => now()]);
        $this->assertSame('importante', $this->mios($this->tokenDelSuper())['periodos_faltan']['insistencia']);
    }

    public function test_el_periodo_desfasado_sale_a_los_7_dias_y_seguimos_nivelando_lo_calla_a_todos(): void
    {
        $hoy = Reloj::ahora()->startOfDay();
        $this->periodoActual(1);
        DB::table('periodos')->where('year_id', $this->anio())->update(['fecha_inicio' => $hoy->copy()->addDays(200)->toDateString()]);
        DB::table('periodos')->where('year_id', $this->anio())->where('numero', 1)->update(['fecha_inicio' => $hoy->copy()->subDays(100)->toDateString()]);

        // A los 6 días del inicio del periodo 2, todavía no.
        DB::table('periodos')->where('year_id', $this->anio())->where('numero', 2)->update(['fecha_inicio' => $hoy->copy()->subDays(6)->toDateString()]);
        $this->assertArrayNotHasKey('periodo_desfasado', $this->mios($this->tokenDelSuper()));

        // A los 7, sí.
        DB::table('periodos')->where('year_id', $this->anio())->where('numero', 2)->update(['fecha_inicio' => $hoy->copy()->subDays(7)->toDateString()]);
        $admin = $this->tokenCon('Admin');
        $p = $this->mios($admin)['periodo_desfasado'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('importante', $p['insistencia']);
        $this->assertSame(['etiqueta' => 'Seguimos nivelando', 'para_todos' => true], $p['posponer']);

        // Lo pospone el Admin y deja de salirle también al superusuario.
        $this->withToken($admin)->putJson('/api/pendientes/ocultar', ['clave' => $p['clave'], 'modo' => 'posponer'])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('pendientes_ocultos')->where('clave', $p['clave'])->value('user_id'));
        $this->assertArrayNotHasKey('periodo_desfasado', $this->mios($this->tokenDelSuper()));

        // Y al cambiar el periodo actual se va solo.
        DB::table('pendientes_ocultos')->delete();
        $this->periodoActual(2);
        $this->assertArrayNotHasKey('periodo_desfasado', $this->mios($admin));
    }

    // ── Plantilla ──────────────────────────────────────────────────────────────────

    public function test_sin_foto_no_se_sabe_y_no_se_descarta(): void
    {
        DB::table('plantilla_fotos')->delete();

        $this->assertArrayNotHasKey('plantilla_sin_propagar', $this->mios($this->tokenDelSuper()));
        $this->withToken($this->tokenDelSuper())->putJson('/api/plantilla-notas/descartar')->assertStatus(422);
        $this->withToken($this->tokenDelSuper())->getJson('/api/plantilla-notas/cambios')
            ->assertStatus(200)->assertJsonPath('propagada', null)->assertJsonPath('cambios', []);
    }

    public function test_un_cambio_despues_de_propagar_sale_y_descartar_lo_devuelve(): void
    {
        $y = $this->anio();
        DB::table('plantilla_fotos')->delete();
        DB::table('unidades_por_defecto')->where('year_id', $y)->update(['deleted_at' => now()]);
        $u = DB::table('unidades_por_defecto')->insertGetId(['definicion' => 'Cognitivo', 'porcentaje' => 100, 'year_id' => $y, 'orden' => 1]);
        FotoDeLaPlantilla::tomar($y, null);
        $super = $this->tokenDelSuper();

        $this->assertArrayNotHasKey('plantilla_sin_propagar', $this->mios($super));

        DB::table('unidades_por_defecto')->where('id', $u)->update(['porcentaje' => 80]);
        $nueva = DB::table('unidades_por_defecto')->insertGetId(['definicion' => 'Actitudinal', 'porcentaje' => 20, 'year_id' => $y, 'orden' => 2]);

        $p = $this->mios($super)['plantilla_sin_propagar'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('La plantilla tiene 2 cambios sin propagar', $p['titular']);
        $this->assertSame(['paso' => 'plantilla'], $p['destino']['query']);

        $this->withToken($super)->getJson('/api/plantilla-notas/cambios')->assertStatus(200)
            ->assertJsonPath('cambios.0.texto', '«Cognitivo»: 100 % → 80 %')
            ->assertJsonPath('cambios.1.texto', 'Unidad nueva «Actitudinal» (20 %)');

        $this->withToken($super)->putJson('/api/plantilla-notas/descartar')->assertStatus(200)
            ->assertJsonPath('restauradas', 1)->assertJsonPath('quitadas', 1);

        $this->assertSame(100, (int) DB::table('unidades_por_defecto')->where('id', $u)->value('porcentaje'));
        $this->assertNotNull(DB::table('unidades_por_defecto')->where('id', $nueva)->value('deleted_at'));
        $this->assertArrayNotHasKey('plantilla_sin_propagar', $this->mios($super));
    }

    public function test_quien_no_edita_la_plantilla_no_ve_sus_cambios(): void
    {
        $this->withToken($this->tokenCon('Secretario'))->getJson('/api/plantilla-notas/cambios')->assertStatus(403);
        $this->withToken($this->tokenCon('Secretario'))->putJson('/api/plantilla-notas/descartar')->assertStatus(403);
    }

    // ── Disciplina ─────────────────────────────────────────────────────────────────

    private function reglaDeDisciplina(int $tardanzas, int $t1, int $t2): void
    {
        DB::table('dis_configuraciones')->updateOrInsert(['year_id' => $this->anio()], [
            'cant_tard_to_ft1' => $tardanzas, 'cant_ft1_to_ft2' => $t1, 'cant_ft2_to_ft3' => $t2,
            'reinicia_por_periodo' => 0, 'deleted_at' => null,
        ]);
    }

    public function test_las_tardanzas_que_ya_dan_situacion_salen_al_coordinador_y_se_van_al_crearla(): void
    {
        $a = $this->alumno();
        $periodo = $this->periodoActual(1);
        $this->reglaDeDisciplina(3, 0, 0);
        DB::table('ausencias')->where('alumno_id', $a->alumno_id)->delete();
        DB::table('dis_procesos')->where('alumno_id', $a->alumno_id)->delete();

        for ($i = 0; $i < 3; $i++) {
            DB::table('ausencias')->insert(['alumno_id' => $a->alumno_id, 'periodo_id' => $periodo, 'tipo' => 'tardanza', 'entrada' => 1]);
        }
        // Las de clase no cuentan.
        DB::table('ausencias')->insert(['alumno_id' => $a->alumno_id, 'periodo_id' => $periodo, 'tipo' => 'tardanza', 'entrada' => 0]);

        $coord = $this->tokenCon('Coord disciplinario');
        $p = $this->mios($coord)['tardanzas_sin_situacion'] ?? null;
        $this->assertNotNull($p);
        $fila = collect($p['filas'])->firstWhere('destino.query.alumno', (int) $a->alumno_id);
        $this->assertNotNull($fila, 'El alumno no está entre las filas.');
        $this->assertSame('3 tardanzas', $fila['nota']);
        // `periodo` sólo va si el colegio cuenta por periodo: depende de la configuración de la base.
        $this->assertSame(
            ['grupo' => (int) $a->grupo_id, 'alumno' => (int) $a->alumno_id, 'abrir' => 'tardanzas'],
            array_diff_key($fila['destino']['query'], ['periodo' => 0])
        );

        $this->assertArrayNotHasKey('tardanzas_sin_situacion', $this->mios($this->tokenCon('Secretario')));

        DB::table('dis_procesos')->insert(['alumno_id' => $a->alumno_id, 'year_id' => $this->anio(), 'periodo_id' => $periodo, 'tipo_situacion' => 1, 'deriva_de_tardanzas' => 1]);
        $filas = $this->mios($coord)['tardanzas_sin_situacion']['filas'] ?? [];
        $this->assertNull(collect($filas)->firstWhere('destino.query.alumno', (int) $a->alumno_id));
    }

    public function test_con_el_umbral_en_cero_la_regla_esta_apagada(): void
    {
        $a = $this->alumno();
        $periodo = $this->periodoActual(1);
        $this->reglaDeDisciplina(0, 0, 0);
        for ($i = 0; $i < 9; $i++) {
            DB::table('ausencias')->insert(['alumno_id' => $a->alumno_id, 'periodo_id' => $periodo, 'tipo' => 'tardanza', 'entrada' => 1]);
        }

        $this->assertArrayNotHasKey('tardanzas_sin_situacion', $this->mios($this->tokenCon('Coord disciplinario')));
    }

    public function test_las_situaciones_se_escalan_y_las_escaladas_ya_no_cuentan(): void
    {
        $a = $this->alumno();
        $periodo = $this->periodoActual(1);
        $this->reglaDeDisciplina(0, 2, 2);
        DB::table('dis_procesos')->where('year_id', $this->anio())->delete();

        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = DB::table('dis_procesos')->insertGetId(['alumno_id' => $a->alumno_id, 'year_id' => $this->anio(), 'periodo_id' => $periodo, 'tipo_situacion' => 1]);
        }

        $coord = $this->tokenCon('Coord disciplinario');
        $p = $this->mios($coord)['situaciones_sin_escalar'] ?? null;
        $this->assertNotNull($p);
        $this->assertStringStartsWith('2 ', $p['filas'][0]['nota']);
        $this->assertSame('tipo1', $p['filas'][0]['destino']['query']['abrir']);

        $t2 = DB::table('dis_procesos')->insertGetId(['alumno_id' => $a->alumno_id, 'year_id' => $this->anio(), 'periodo_id' => $periodo, 'tipo_situacion' => 2, 'deriva_de_tipos1' => 1]);
        DB::table('dis_procesos')->whereIn('id', $ids)->update(['become_id' => $t2]);

        $this->assertArrayNotHasKey('situaciones_sin_escalar', $this->mios($coord));
    }

    // ── Matrícula y personas ───────────────────────────────────────────────────────

    public function test_cambiar_el_estado_sella_estado_desde(): void
    {
        $a = $this->alumno();
        $m = Matricula::find($a->matricula_id);
        $m->estado = 'ASIS';
        $m->save();

        $this->assertSame(Reloj::ahora()->toDateString(), DB::table('matriculas')->where('id', $a->matricula_id)->value('estado_desde'));
    }

    public function test_un_asistente_viejo_sale_desde_el_periodo_2_y_los_dias_se_configuran(): void
    {
        $a = $this->alumno();
        DB::table('matriculas')->where('id', $a->matricula_id)->update([
            'estado' => 'ASIS', 'estado_desde' => Reloj::ahora()->subDays(20)->toDateString(),
        ]);
        DB::table('years')->where('id', $this->anio())->update(['dias_max_prematricula' => 10]);
        $secretaria = $this->tokenCon('Secretario');

        $this->periodoActual(1);
        $this->assertArrayNotHasKey('prematricula_vieja', $this->mios($secretaria));

        $this->periodoActual(2);
        $p = $this->mios($secretaria)['prematricula_vieja'] ?? null;
        $this->assertNotNull($p);
        $this->assertContains('asistente hace 20 días', array_column($p['filas'], 'nota'));

        DB::table('years')->where('id', $this->anio())->update(['dias_max_prematricula' => 30]);
        $filas = $this->mios($secretaria)['prematricula_vieja']['filas'] ?? [];
        $this->assertNotContains('asistente hace 20 días', array_column($filas, 'nota'));
    }

    public function test_los_dias_de_prematricula_son_un_numero_de_1_a_365(): void
    {
        $super = $this->tokenDelSuper();

        foreach (['0', '366', 'diez'] as $malo) {
            $this->withToken($super)->putJson('/api/years/toggle-cambiar-valor', ['year_id' => $this->anio(), 'campo' => 'dias_max_prematricula', 'valor' => $malo])
                ->assertStatus(422);
        }

        $this->withToken($super)->putJson('/api/years/toggle-cambiar-valor', ['year_id' => $this->anio(), 'campo' => 'dias_max_prematricula', 'valor' => '15'])
            ->assertStatus(200);
        $this->assertSame(15, (int) DB::table('years')->where('id', $this->anio())->value('dias_max_prematricula'));
    }

    public function test_un_alumno_sin_documento_sale_con_lo_que_le_falta(): void
    {
        $a = $this->alumno();
        DB::table('alumnos')->where('id', $a->alumno_id)->update(['documento' => ' ']);

        $p = $this->mios($this->tokenCon('Secretario'))['alumnos_sin_datos'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('posponible', $p['insistencia']);
        $notas = array_column($p['filas'], 'nota');
        $this->assertTrue(in_array('sin documento', $notas, true) || in_array('sin documento ni correo', $notas, true));
    }

    public function test_un_docente_con_contrato_y_sin_cedula_sale(): void
    {
        $profe = DB::selectOne('SELECT c.profesor_id FROM contratos c WHERE c.year_id = ? AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1', [$this->anio()]);
        $this->assertNotNull($profe, 'El seed no tiene contratos en el año actual.');
        DB::table('profesores')->where('id', $profe->profesor_id)->update(['num_doc' => null]);

        $this->assertArrayHasKey('docentes_sin_datos', $this->mios($this->tokenCon('Secretario')));
    }

    // ── Configuración del año ──────────────────────────────────────────────────────

    public function test_grupos_sin_titular_y_asignaturas_sin_docente(): void
    {
        $a = $this->alumno();
        DB::table('grupos')->where('id', $a->grupo_id)->update(['titular_id' => null]);
        DB::table('asignaturas')->where('grupo_id', $a->grupo_id)->whereNull('deleted_at')->orderBy('id')->limit(1)->update(['profesor_id' => null]);

        $mios = $this->mios($this->tokenCon('Coord académico'));
        $this->assertSame('posponible', $mios['grupos_sin_titular']['insistencia'] ?? null);
        $this->assertSame('importante', $mios['asignaturas_sin_docente']['insistencia'] ?? null);
        $this->assertContains('sin docente', array_column($mios['asignaturas_sin_docente']['filas'], 'nota'));
    }

    public function test_la_escala_sin_bandas_o_sin_perdida(): void
    {
        $y = $this->anio();
        DB::table('escalas_de_valoracion')->where('year_id', $y)->update(['deleted_at' => now()]);
        $this->assertSame('No hay ninguna banda', $this->mios($this->tokenDelSuper())['escala_incompleta']['filas'][0]['texto'] ?? null);

        DB::table('escalas_de_valoracion')->insert(['desempenio' => 'Bajo', 'porc_inicial' => 0, 'porc_final' => 59, 'perdido' => 0, 'year_id' => $y]);
        DB::table('escalas_de_valoracion')->insert(['desempenio' => 'Alto', 'porc_inicial' => 70, 'porc_final' => 100, 'perdido' => 0, 'year_id' => $y]);
        $textos = array_column($this->mios($this->tokenDelSuper())['escala_incompleta']['filas'] ?? [], 'texto');
        $this->assertContains('De 60 a 69,99', $textos);
        $this->assertContains('Ninguna banda está marcada como perdida', $textos);

        DB::table('escalas_de_valoracion')->where('year_id', $y)->where('desempenio', 'Bajo')->update(['porc_final' => 69, 'perdido' => 1]);
        $this->assertArrayNotHasKey('escala_incompleta', $this->mios($this->tokenDelSuper()));
    }

    public function test_la_plantilla_vacia_se_silencia_y_la_que_no_suma_no(): void
    {
        $y = $this->anio();
        DB::table('unidades_por_defecto')->where('year_id', $y)->update(['deleted_at' => now()]);
        $p = $this->mios($this->tokenDelSuper())['plantilla_que_no_suma'] ?? null;
        $this->assertSame('silenciable', $p['insistencia'] ?? null);

        DB::table('unidades_por_defecto')->insert(['definicion' => 'Cognitivo', 'porcentaje' => 60, 'year_id' => $y, 'orden' => 1]);
        $p = $this->mios($this->tokenDelSuper())['plantilla_que_no_suma'] ?? null;
        $this->assertSame('importante', $p['insistencia'] ?? null);
        $this->assertSame([['texto' => 'La plantilla general', 'nota' => 'suma 60 %', 'aviso' => true]], $p['filas']);
    }

    public function test_la_ficha_incompleta_es_silenciable(): void
    {
        DB::table('years')->where('id', $this->anio())->update(['codigo_dane' => null]);

        $p = $this->mios($this->tokenCon('Secretario'))['ficha_incompleta'] ?? null;
        $this->assertSame('silenciable', $p['insistencia'] ?? null);
        $this->assertContains('Código DANE', array_column($p['filas'], 'texto'));
    }

    // ── Firmas ─────────────────────────────────────────────────────────────────────

    public function test_una_firma_por_aprobar_sale_a_quien_aprueba_menos_a_quien_la_pidio(): void
    {
        $profe = DB::selectOne('SELECT u.id FROM users u INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            INNER JOIN periodos pe ON pe.id = u.periodo_id INNER JOIN years y ON y.id = pe.year_id AND y.actual = 1
            WHERE u.deleted_at IS NULL AND u.is_active = 1 AND u.is_superuser = 0 ORDER BY u.id LIMIT 1');
        $this->assertNotNull($profe);
        DB::table('change_asked')->whereNull('answered_by')->update(['deleted_at' => now()]);
        $dato = DB::table('change_asked_data')->insertGetId(['firma_id_new' => 1]);
        DB::table('change_asked')->insert(['asked_by_user_id' => $profe->id, 'data_id' => $dato]);

        $p = $this->mios($this->tokenCon('Secretario'))['firmas_por_aprobar'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('importante', $p['insistencia']);
        $this->assertSame('1 firma de titular espera tu aprobación', $p['titular']);

        $this->assertArrayNotHasKey('firmas_por_aprobar', $this->mios($this->tokenDe(DB::table('users')->where('id', $profe->id)->value('username'))));
    }
}
