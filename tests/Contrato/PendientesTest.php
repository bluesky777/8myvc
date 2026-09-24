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

    public function test_un_docente_no_ve_nada(): void
    {
        $this->escenario();
        $profe = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.is_superuser = 0 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM role_user ru INNER JOIN roles r ON r.id = ru.role_id
                   WHERE ru.user_id = u.id AND r.name <> "Profesor")
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($profe);
        $this->assertSame([], $this->tipos($this->tokenDe($profe->username)));
    }

    public function test_alumno_y_acudiente_se_quedan_en_la_puerta(): void
    {
        $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->getJson('/api/pendientes/mios')->assertStatus(403);
        $this->withToken($this->tokenDe($this->usuarioDeTipo('Acudiente')->username))
            ->getJson('/api/pendientes/mios')->assertStatus(403);
    }

    /** Los seis cargos de directivo, uno a uno. */
    public function test_cada_directivo_ve_acudientes_celular_intensidad_y_jefes(): void
    {
        $this->escenario();
        // Un área que se dicta este año y sin jefe, para que `jefes_de_area` tenga de qué hablar.
        $yearId = (int) DB::table('years')->where('actual', 1)->value('id');
        DB::table('jefes_de_area')->where('year_id', $yearId)->delete();

        foreach (['Admin', 'Rector', 'Secretario', 'Coord académico', 'Coord disciplinario'] as $rol) {
            $tipos = $this->tipos($this->tokenCon($rol));

            foreach (['acudientes', 'celular', 'intensidad_horaria', 'jefes_de_area'] as $tipo) {
                $this->assertContains($tipo, $tipos, "{$rol} debería ver {$tipo}.");
            }

            DB::table('role_user')->where('user_id', $this->usuarioLlanoDelPersonal()->id)->delete();
        }
    }

    public function test_a_secretaria_le_sale_primero_lo_suyo(): void
    {
        $this->escenario();

        $tipos = $this->tipos($this->tokenCon('Secretario'));

        $this->assertContains($tipos[0], ['acudientes', 'celular']);
        $this->assertContains($tipos[1], ['acudientes', 'celular']);
    }

    public function test_a_coordinacion_academica_le_sale_la_intensidad_antes_que_lo_de_secretaria(): void
    {
        $this->escenario();

        $tipos = $this->tipos($this->tokenCon('Coord académico'));

        $this->assertLessThan(array_search('acudientes', $tipos, true), array_search('intensidad_horaria', $tipos, true));
    }

    public function test_la_entrega_de_boletines_sale_dentro_de_los_14_dias_y_no_fuera(): void
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

        // A 20 días: todavía no.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->addDays(20)->toDateString()]);
        $this->assertNotContains('entrega_boletines', $this->tipos($token));

        // A 7 días: sale, primero, y con su texto.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->addDays(7)->toDateString()]);
        $r = $this->withToken($token)->getJson('/api/pendientes/mios')->assertStatus(200);
        $primero = $r->json('pendientes.0');
        $this->assertSame('entrega_boletines', $primero['tipo']);
        $this->assertStringStartsWith('Faltan 7 días para entregar los boletines del Periodo', $primero['titular']);
        $this->assertSame($e->periodo_id, $primero['destino']['query']['registro']);

        // Ya pasó: no sale.
        DB::table('periodos')->where('id', $e->periodo_id)->update(['fecha_entrega_boletines' => $hoy->copy()->subDay()->toDateString()]);
        $this->assertNotContains('entrega_boletines', $this->tipos($token));
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
