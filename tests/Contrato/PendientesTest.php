<?php

namespace Tests\Contrato;

use App\Http\Controllers\PendientesController;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **`GET pendientes/mios`: quién ve qué, y en qué orden.**
 *
 * El aviso de «cosas pendientes» de `myvc_front/app2`. Lo que importa proteger no es
 * el texto sino el reparto: un docente que viera «43 estudiantes no tienen acudiente»
 * al entrar recibiría un trabajo que no es suyo, y una secretaria a la que le saliera
 * primero lo de coordinación leería de arriba lo que no puede resolver.
 *
 * Los datos se fabrican dentro de la transacción del test (una asignatura sin IH, un
 * alumno sin acudiente ni celular, una fecha de entrega) para no depender de lo que el
 * seed traiga hoy.
 */
class PendientesTest extends CasoDeContrato
{
    /** El año actual y un alumno matriculado en él, dejado sin acudiente y sin celular. */
    private function escenario(): object
    {
        $fila = DB::selectOne('SELECT y.id AS year_id, p.id AS periodo_id, m.alumno_id, g.id AS grupo_id
            FROM years y
            INNER JOIN periodos p ON p.year_id = y.id AND p.actual = 1 AND p.deleted_at IS NULL
            INNER JOIN grupos g ON g.year_id = y.id AND g.deleted_at IS NULL
            INNER JOIN matriculas m ON m.grupo_id = g.id AND m.estado = "MATR" AND m.deleted_at IS NULL
            INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
            WHERE y.actual = 1 AND y.deleted_at IS NULL
            ORDER BY m.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene un alumno matriculado en el año actual.');

        DB::table('parentescos')->where('alumno_id', $fila->alumno_id)->delete();
        DB::table('alumnos')->where('id', $fila->alumno_id)->update(['celular' => ' 0 ']);

        // Una asignatura del año sin intensidad horaria.
        DB::table('asignaturas')->where('grupo_id', $fila->grupo_id)->whereNull('deleted_at')
            ->orderBy('id')->limit(1)->update(['creditos' => null]);

        return $fila;
    }

    /** El personal llano con los roles pedidos: el mismo sujeto, cambia sólo `role_user`. */
    private function tokenCon(string ...$roles): string
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        foreach ($roles as $rol) {
            $fila = DB::table('roles')->where('name', $rol)->whereNull('deleted_at')->first();
            $rolId = (int) ($fila->id ?? DB::table('roles')->insertGetId([
                'name' => $rol, 'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $rolId]);
        }

        return $this->tokenDe($usuario->username);
    }

    /** @return list<string> */
    private function tipos(string $token): array
    {
        $r = $this->withToken($token)->getJson('/api/pendientes/mios');
        $r->assertStatus(200);

        return array_column($r->json('pendientes'), 'tipo');
    }

    public function test_la_forma_de_la_respuesta(): void
    {
        $this->escenario();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->getJson('/api/pendientes/mios');

        $r->assertStatus(200);
        $this->compararConInstantanea('pendientes-mios', $this->forma($r->json()));
    }

    public function test_el_superusuario_ve_los_de_directivos(): void
    {
        $e = $this->escenario();
        $tipos = $this->tipos($this->tokenDe($this->usuarioDeTipo('Usuario')->username));

        $this->assertContains('acudientes', $tipos);
        $this->assertContains('celular', $tipos);
        $this->assertContains('intensidad_horaria', $tipos);
    }

    public function test_el_personal_sin_cargo_no_ve_nada(): void
    {
        $this->escenario();

        $this->assertSame([], $this->tipos($this->tokenCon()));
    }

    /** Un docente sin cargo, con asignaturas en el año actual, y su token. */
    private function docente(): object
    {
        $profe = DB::selectOne('SELECT u.id, u.username, p.id AS profesor_id FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            INNER JOIN asignaturas a ON a.profesor_id = p.id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.is_superuser = 0 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM role_user ru INNER JOIN roles r ON r.id = ru.role_id
                   WHERE ru.user_id = u.id AND r.name <> "Profesor")
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profe, 'El seed no tiene un docente llano con asignaturas este año.');
        $profe->token = $this->tokenDe($profe->username);
        $profe->periodo_id = (int) DB::table('users')->where('id', $profe->id)->value('periodo_id');

        return $profe;
    }

    public function test_un_docente_sin_entrega_cerca_no_ve_nada(): void
    {
        $this->escenario();
        $d = $this->docente();
        DB::table('periodos')->update(['fecha_entrega_boletines' => null]);

        $this->assertSame([], $this->tipos($d->token));
    }

    /** Plan §2.4: el docente ve las suyas y, si es titular, las de su grupo; nunca las de otros. */
    public function test_un_docente_ve_sus_asignaturas_y_las_de_su_grupo_sin_cerrar(): void
    {
        $this->escenario();
        $d = $this->docente();
        $hoy = Reloj::ahora()->startOfDay();

        DB::table('cierres_asignatura')->where('periodo_id', $d->periodo_id)->delete();
        DB::table('periodos')->update(['fecha_entrega_boletines' => null]);
        DB::table('periodos')->where('id', $d->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->addDays(3)->toDateString()]);

        $yearId = (int) DB::table('periodos')->where('id', $d->periodo_id)->value('year_id');
        $grupo = DB::selectOne('SELECT g.id FROM grupos g INNER JOIN asignaturas a ON a.grupo_id = g.id AND a.deleted_at IS NULL
            WHERE g.year_id = ? AND g.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM asignaturas o WHERE o.grupo_id = g.id AND o.deleted_at IS NULL AND (o.profesor_id IS NULL OR o.profesor_id <> ?))
            ORDER BY g.id LIMIT 1', [$yearId, $d->profesor_id]);
        DB::table('grupos')->where('titular_id', $d->profesor_id)->update(['titular_id' => null]);
        DB::table('grupos')->where('id', $grupo->id)->update(['titular_id' => $d->profesor_id]);

        $esperadas = (int) DB::selectOne('SELECT COUNT(*) AS n FROM asignaturas a INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            WHERE g.year_id = ? AND a.deleted_at IS NULL AND (a.profesor_id = ? OR g.id = ?)', [$yearId, $d->profesor_id, $grupo->id])->n;

        $r = $this->withToken($d->token)->getJson('/api/pendientes/mios')->assertStatus(200);

        $this->assertSame(['entrega_boletines'], array_column($r->json('pendientes'), 'tipo'));
        $this->assertSame($esperadas, $r->json('pendientes.0.total_filas'));
        $this->assertStringStartsWith('Faltan 3 días', $r->json('pendientes.0.titular'));
    }

    public function test_alumno_y_acudiente_se_quedan_en_la_puerta(): void
    {
        $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->getJson('/api/pendientes/mios')->assertStatus(403);
        $this->withToken($this->tokenDe($this->usuarioDeTipo('Acudiente')->username))
            ->getJson('/api/pendientes/mios')->assertStatus(403);
    }

    /** Los cargos de directivo, uno a uno. Los jefes de área, sólo coordinación académica (plan §4). */
    public function test_cada_directivo_ve_lo_suyo(): void
    {
        $this->escenario();
        $yearId = (int) DB::table('years')->where('actual', 1)->value('id');
        DB::table('jefes_de_area')->where('year_id', $yearId)->delete();

        foreach (['Admin', 'Rector', 'Secretario', 'Coord académico', 'Coord disciplinario'] as $rol) {
            $tipos = $this->tipos($this->tokenCon($rol));

            foreach (['acudientes', 'celular', 'intensidad_horaria'] as $tipo) {
                $this->assertContains($tipo, $tipos, "{$rol} debería ver {$tipo}.");
            }

            $rol === 'Coord académico'
                ? $this->assertContains('jefes_de_area', $tipos)
                : $this->assertNotContains('jefes_de_area', $tipos, "{$rol} no debería ver los jefes de área.");

            DB::table('role_user')->where('user_id', $this->usuarioLlanoDelPersonal()->id)->delete();
        }
    }

    /** Firmes, luego Posponibles, luego Silenciables (plan §2.1). */
    public function test_el_orden_va_por_insistencia(): void
    {
        $this->escenario();
        $yearId = (int) DB::table('years')->where('actual', 1)->value('id');
        DB::table('jefes_de_area')->where('year_id', $yearId)->delete();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))->getJson('/api/pendientes/mios');
        $peso = ['firme' => 0, 'posponible' => 1, 'silenciable' => 2];
        $pesos = array_map(fn ($i) => $peso[$i], array_column($r->json('pendientes'), 'insistencia'));

        $ordenados = $pesos;
        sort($ordenados);
        $this->assertSame($ordenados, $pesos);
        $this->assertContains('silenciable', array_column($r->json('pendientes'), 'insistencia'));
    }

    public function test_a_secretaria_le_salen_primero_lo_suyo_entre_los_posponibles(): void
    {
        $this->escenario();

        $tipos = $this->tipos($this->tokenCon('Secretario'));
        $posponibles = array_values(array_intersect($tipos, ['acudientes', 'celular']));

        $this->assertSame(['acudientes', 'celular'], $posponibles);
    }

    public function test_posponer_oculta_siete_dias_y_mostrar_lo_devuelve(): void
    {
        $this->escenario();
        $token = $this->tokenCon('Secretario');

        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => $this->claveDe($token, 'acudientes'), 'modo' => 'posponer'])
            ->assertStatus(200);

        $r = $this->withToken($token)->getJson('/api/pendientes/mios')->assertStatus(200);
        $this->assertNotContains('acudientes', array_column($r->json('pendientes'), 'tipo'));
        $this->assertSame(['acudientes'], array_column($r->json('ocultos'), 'tipo'));
        $hasta = Reloj::desdeTexto($r->json('ocultos.0.oculto_hasta'));
        $this->assertEqualsWithDelta(7, Reloj::ahora()->diffInDays($hasta), 0.01);

        // Pasados los 7 días, vuelve solo.
        DB::table('pendientes_ocultos')->update(['hasta' => Reloj::ahora()->subMinute()->toDateTimeString()]);
        $this->assertContains('acudientes', $this->tipos($token));

        // Y «mostrar» lo devuelve antes.
        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => 'acudientes:y='.$this->anio(), 'modo' => 'posponer'])->assertStatus(200);
        $this->withToken($token)->putJson('/api/pendientes/mostrar', ['clave' => 'acudientes:y='.$this->anio()])->assertStatus(200);
        $this->assertContains('acudientes', $this->tipos($token));
    }

    public function test_un_firme_no_se_oculta_y_un_posponible_no_se_silencia(): void
    {
        $this->escenario();
        $token = $this->tokenCon('Coord académico');

        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => 'intensidad_horaria:y='.$this->anio(), 'modo' => 'posponer'])
            ->assertStatus(422);
        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => 'acudientes:y='.$this->anio(), 'modo' => 'silenciar'])
            ->assertStatus(422);
        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => 'no-existe', 'modo' => 'posponer'])
            ->assertStatus(422);
    }

    public function test_silenciar_los_jefes_de_area_es_por_el_anio_y_de_cada_uno(): void
    {
        $this->escenario();
        DB::table('jefes_de_area')->where('year_id', $this->anio())->delete();
        $token = $this->tokenCon('Coord académico');

        $this->withToken($token)->putJson('/api/pendientes/ocultar', ['clave' => 'jefes_de_area:y='.$this->anio(), 'modo' => 'silenciar'])
            ->assertStatus(200)->assertJsonPath('hasta', null);

        $this->assertNotContains('jefes_de_area', $this->tipos($token));
        // Al superusuario le sigue saliendo: lo ocultado es de quien lo ocultó.
        $this->assertContains('jefes_de_area', $this->tipos($this->tokenDe($this->usuarioDeTipo('Usuario')->username)));
    }

    private function anio(): int
    {
        return (int) DB::table('years')->where('actual', 1)->value('id');
    }

    private function claveDe(string $token, string $tipo): string
    {
        $r = $this->withToken($token)->getJson('/api/pendientes/mios');

        foreach ($r->json('pendientes') as $p) {
            if ($p['tipo'] === $tipo) {
                return $p['clave'];
            }
        }

        $this->fail("No sale {$tipo}.");
    }

    public function test_la_entrega_de_boletines_sale_a_7_dias_y_vencida_y_no_antes(): void
    {
        if (! Schema::hasColumn('periodos', 'fecha_entrega_boletines')) {
            $this->markTestSkipped('Falta la migración de la fecha de entrega de boletines (2026_09_24_700000).');
        }

        $e = $this->escenario();
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $hoy = Reloj::ahora()->startOfDay();

        DB::table('cierres_asignatura')->where('periodo_id', $e->periodo_id)->delete();

        // Sin fecha: no sale.
        DB::table('periodos')->where('year_id', $e->year_id)->update(['fecha_entrega_boletines' => null]);
        $this->assertNotContains('entrega_boletines', $this->tipos($token));

        // A 8 días: todavía no.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->addDays(8)->toDateString()]);
        $this->assertNotContains('entrega_boletines', $this->tipos($token));

        // A 7 días: sale, primero, Firme y con su texto.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->addDays(7)->toDateString()]);
        $r = $this->withToken($token)->getJson('/api/pendientes/mios')->assertStatus(200);
        $primero = $r->json('pendientes.0');
        $this->assertSame('entrega_boletines', $primero['tipo']);
        $this->assertSame('firme', $primero['insistencia']);
        $this->assertStringStartsWith('Faltan 7 días para entregar los boletines del Periodo', $primero['titular']);
        $this->assertSame($e->periodo_id, $primero['destino']['query']['registro']);

        // Ya pasó: sigue saliendo, diciendo que venció.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->subDays(2)->toDateString()]);
        $r = $this->withToken($token)->getJson('/api/pendientes/mios')->assertStatus(200);
        $this->assertStringContainsString('venció hace 2 días', $r->json('pendientes.0.titular'));
    }

    public function test_el_anio_es_el_que_eligio_el_usuario(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $otro = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN years y ON y.id = p.year_id AND y.actual = 0 AND y.deleted_at IS NULL
            WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 1');
        $this->assertNotNull($otro);

        DB::table('users')->where('id', $usuario->id)->update(['periodo_id' => $otro->id]);

        $this->withToken($token)->getJson('/api/pendientes/mios')
            ->assertStatus(200)->assertJsonPath('year_id', (int) $otro->year_id);
    }

    public function test_que_es_un_celular(): void
    {
        foreach (['', '   ', '0', '123456', '0000000', null, '00-00-000'] as $basura) {
            $this->assertFalse(PendientesController::esCelular($basura), var_export($basura, true));
        }

        foreach (['3001234567', '300 123 4567', '(605) 7654321', '5551234'] as $bueno) {
            $this->assertTrue(PendientesController::esCelular($bueno), $bueno);
        }
    }
}
