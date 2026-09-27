<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * EL ALUMNO SIN USUARIO NI MATRÍCULA (27 sep 2026): el que crea «Boletines de otros colegios».
 *
 * Lo que tiene que sostenerse: se encuentra buscándolo por el NOMBRE (antes sólo por el documento),
 * y quien edita alumnos le crea la cuenta; una segunda vez, 422; y un alumno no puede.
 */
class AlumnoSinUsuarioTest extends CasoDeContrato
{
    private function alumnoSinNada(): int
    {
        return (int) DB::table('alumnos')->insertGetId([
            'nombres' => 'ZENAIDA PRUDENCIA', 'apellidos' => 'QUINTERO ÑUSTES', 'sexo' => 'F', 'documento' => '9990004445',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Usuario')->username), 'Accept' => 'application/json'];
    }

    public function test_se_encuentra_por_el_nombre_aunque_nunca_se_haya_matriculado(): void
    {
        $id = $this->alumnoSinNada();

        $personas = $this->putJson('/api/alumnos/personas-check', ['texto' => 'zenaida prudencia', 'todos_anios' => true], $this->h())
            ->assertStatus(200)->json('personas');

        $this->assertContains($id, array_map(fn ($p) => (int) $p['alumno_id'], $personas));
    }

    public function test_quien_edita_alumnos_le_crea_la_cuenta_una_sola_vez(): void
    {
        $id = $this->alumnoSinNada();
        $h = $this->h();

        $r = $this->postJson("/api/alumnos/{$id}/crear-usuario", [], $h)->assertStatus(200)->json();
        $this->assertSame('9990004445', $r['username']);

        $usuario = DB::selectOne('SELECT u.tipo FROM alumnos a INNER JOIN users u ON u.id = a.user_id WHERE a.id = ?', [$id]);
        $this->assertSame('Alumno', $usuario->tipo);

        $this->olvidarControladores();
        $this->postJson("/api/alumnos/{$id}/crear-usuario", [], $h)->assertStatus(422);
    }

    public function test_un_alumno_no_crea_cuentas(): void
    {
        $id = $this->alumnoSinNada();
        $h = ['Authorization' => 'Bearer '.$this->tokenDe($this->usuarioDeTipo('Alumno')->username), 'Accept' => 'application/json'];

        $this->postJson("/api/alumnos/{$id}/crear-usuario", [], $h)->assertStatus(403);
    }
}
