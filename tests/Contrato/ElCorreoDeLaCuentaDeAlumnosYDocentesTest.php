<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El correo de la FICHA de alumnos y docentes llega a su CUENTA — si la cuenta no
 * tenía uno propio.
 *
 * Es el hermano de `ElCorreoDeLaCuentaDelAcudienteTest`. `alumnos.email` y
 * `profesores.email` son la ficha; `users.email` es la cuenta, y **la recuperación
 * de contraseña sólo mira la cuenta** (`LoginController:240-266`). Nadie las
 * sincronizaba: corregir el correo en la ficha no lo ponía donde el reseteo busca.
 * Decisión de Joseth del 24 sep 2026 (PLAN-COSAS-PENDIENTES §2.6): *el que cuenta
 * es el de la cuenta.*
 *
 * Las reglas que se fijan, todas en `CorreoDeLaCuenta::seguirALaFicha`:
 *
 * 1. cuenta **vacía** → recibe la ficha;
 * 2. cuenta **igual a la ficha de antes** → la sigue;
 * 3. cuenta con **otro** correo → no se toca;
 * 4. el correo ya lo tiene **otra cuenta viva** → no se escribe (el reseteo se
 *    queda con la primera fila y el otro no se enteraría);
 * 5. el cliente **editó** `email2` en la misma petición → gana lo que mandó.
 */
class ElCorreoDeLaCuentaDeAlumnosYDocentesTest extends CasoDeContrato
{
    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($fila->username);
    }

    private function correo(string $quien): string
    {
        return $quien.'.'.random_int(100000, 999999).'@ejemplo.com';
    }

    private function cuenta(int $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('email');
    }

    /** Un alumno con cuenta, con la ficha y la cuenta puestas como pida el caso. */
    private function alumno(?string $ficha, ?string $cuenta): object
    {
        $a = DB::selectOne('SELECT a.id, a.user_id, a.nombres, a.apellidos, a.documento,
                a.ciudad_nac, a.ciudad_doc, a.tipo_doc, u.username
            FROM alumnos a INNER JOIN users u ON u.id = a.user_id
            WHERE a.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY a.id LIMIT 1');
        $this->assertNotNull($a, 'El seed necesita un alumno con cuenta.');

        DB::table('alumnos')->where('id', $a->id)->update(['email' => $ficha]);
        DB::table('users')->where('id', $a->user_id)->update(['email' => $cuenta]);

        return $a;
    }

    /** El cuerpo de la ficha del alumno, con `username` para que guarde (§119). */
    private function guardarAlumno(object $a, array $extra): void
    {
        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/alumnos/update/'.$a->id, array_merge([
                'nombres' => $a->nombres,
                'apellidos' => $a->apellidos,
                'documento' => $a->documento,
                'ciudad_nac' => ['id' => $a->ciudad_nac],
                'ciudad_doc' => ['id' => $a->ciudad_doc],
                'tipo_doc' => ['id' => $a->tipo_doc],
                'tipo_sangre' => ['sangre' => 'O+'],
                'grupo' => ['id' => null],
                'username' => $a->username,
            ], $extra))->assertStatus(200);
    }

    private function profesor(?string $ficha, ?string $cuenta): object
    {
        $p = DB::selectOne('SELECT p.id, p.nombres, p.apellidos, p.user_id
              FROM profesores p INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
             WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1');
        $this->assertNotNull($p, 'El seed no tiene ningún profesor con cuenta.');

        DB::table('profesores')->where('id', $p->id)->update(['email' => $ficha]);
        DB::table('users')->where('id', $p->user_id)->update(['email' => $cuenta]);

        return $p;
    }

    public function test_alumno_con_la_cuenta_vacia_recibe_el_correo_de_la_ficha(): void
    {
        $a = $this->alumno(null, null);
        $nuevo = $this->correo('alumno');

        // Sin `email2`: es la ficha la que se edita.
        $this->guardarAlumno($a, ['email' => $nuevo]);

        $this->assertSame($nuevo, DB::table('alumnos')->where('id', $a->id)->value('email'));
        $this->assertSame($nuevo, $this->cuenta((int) $a->user_id),
            'La cuenta estaba vacía y el correo se quedó sólo en la ficha: el reseteo no lo encuentra.');
    }

    /**
     * La pantalla de edición devuelve en `email2` el correo de la cuenta que leyó
     * (`AlumnosEditCtrl.ts:122`). Ese eco no es una decisión: si cuenta y ficha iban
     * juntas, siguen juntas.
     */
    public function test_alumno_con_la_cuenta_igual_a_la_ficha_la_sigue_aunque_venga_el_eco(): void
    {
        $viejo = $this->correo('viejo');
        $a = $this->alumno($viejo, $viejo);
        $nuevo = $this->correo('nuevo');

        $this->guardarAlumno($a, ['email' => $nuevo, 'email2' => $viejo]);

        $this->assertSame($nuevo, $this->cuenta((int) $a->user_id));
    }

    public function test_alumno_con_un_correo_propio_en_la_cuenta_no_se_toca(): void
    {
        $propio = $this->correo('propio');
        $a = $this->alumno($this->correo('ficha'), $propio);

        $this->guardarAlumno($a, ['email' => $this->correo('otra.ficha')]);

        $this->assertSame($propio, $this->cuenta((int) $a->user_id),
            'La cuenta tenía otro correo, puesto a propósito, y la ficha lo pisó.');
    }

    public function test_si_el_cliente_edita_email2_gana_lo_que_mando(): void
    {
        $viejo = $this->correo('viejo');
        $a = $this->alumno($viejo, $viejo);
        $cuenta = $this->correo('cuenta');

        $this->guardarAlumno($a, ['email' => $this->correo('ficha'), 'email2' => $cuenta]);

        $this->assertSame($cuenta, $this->cuenta((int) $a->user_id));
    }

    public function test_un_correo_que_ya_tiene_otra_cuenta_no_se_copia(): void
    {
        $ocupado = $this->correo('ocupado');
        $otra = DB::selectOne('SELECT id FROM users WHERE deleted_at IS NULL AND tipo = "Usuario" ORDER BY id LIMIT 1');
        DB::table('users')->where('id', $otra->id)->update(['email' => $ocupado]);

        $p = $this->profesor(null, null);

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/profesores/update/'.$p->id, [
                'id' => $p->id, 'nombres' => $p->nombres, 'apellidos' => $p->apellidos,
                'email_usu' => $ocupado,
            ])->assertStatus(200);

        $this->assertSame($ocupado, DB::table('profesores')->where('id', $p->id)->value('email'),
            'La ficha sí se guarda: la colisión es cosa de la cuenta.');
        $this->assertNull($this->cuenta((int) $p->user_id),
            'Dos cuentas con el mismo correo: el reseteo le manda el enlace a la primera y la otra no se entera.');
    }

    /** La rejilla de docentes que sólo corrige el celular también lo arregla. */
    public function test_docente_con_la_cuenta_vacia_recibe_el_correo_de_la_ficha(): void
    {
        $ficha = $this->correo('docente');
        $p = $this->profesor($ficha, null);

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/profesores/update/'.$p->id, [
                'id' => $p->id, 'nombres' => $p->nombres, 'apellidos' => $p->apellidos,
                'celular' => '3001234567',
            ])->assertStatus(200);

        $this->assertSame($ficha, $this->cuenta((int) $p->user_id));
    }

    public function test_el_perfil_propio_tambien_lleva_la_ficha_a_la_cuenta(): void
    {
        $p = $this->profesor(null, null);
        $nuevo = $this->correo('perfil');

        $this->withToken($this->tokenDelSuperusuario())
            ->putJson('/api/perfiles/update/'.$p->id, [
                'tipo' => 'Profesor', 'persona_id' => $p->id,
                'email_persona' => $nuevo,
            ])->assertStatus(200);

        $this->assertSame($nuevo, $this->cuenta((int) $p->user_id));
    }

    /** Lo que no es una dirección no llega a la cuenta, y la cuenta no se vacía. */
    public function test_el_literal_de_la_app_vieja_no_llega_a_la_cuenta(): void
    {
        $a = $this->alumno(null, null);

        $this->guardarAlumno($a, ['email' => '@gmail.com']);

        $this->assertNull($this->cuenta((int) $a->user_id));
    }
}
