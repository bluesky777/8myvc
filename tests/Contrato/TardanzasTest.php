<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las seis rutas de Tardanzas, que autentican por el cuerpo y no por token.
 *
 * Son las únicas de la API que llevan `withoutMiddleware('auth.token')` sin ser
 * públicas: el lector es un aparato montado en la puerta del colegio y manda
 * usuario y contraseña en CADA petición. Eso las deja fuera del barrido
 * —que golpea con un token— y fuera de `AutorizacionTest`, que mira guards.
 * Hasta este test nadie había comprobado ninguna de las seis respuestas: eran
 * cinco de los cinco controladores que la medición de cobertura del 20 de agosto
 * dio con cero rutas comprobadas.
 *
 * Lo que fijan estos tests no es la forma de la respuesta sino **quién la
 * consigue**, que es lo que resultó estar mal. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §25.
 */
class TardanzasTest extends CasoDeContrato
{
    /** Las credenciales que espera el lector, en el cuerpo de cada petición. */
    private function credencialesDe(string $tipo): array
    {
        return [
            'username' => $this->usuarioDeTipo($tipo)->username,
            'password' => self::CLAVE,
        ];
    }

    public function test_el_lector_deja_entrar_al_personal(): void
    {
        foreach (['Profesor', 'Usuario'] as $tipo) {
            $r = $this->postJson('/api/tardanzas/login', $this->credencialesDe($tipo));

            $r->assertStatus(200);
            $this->assertNotNull($r->json('user_id'), "El lector no reconoció a un {$tipo}.");
            $this->assertNotNull($r->json('periodo_id'));
        }
    }

    /**
     * Un alumno o un acudiente no entra al lector, y antes sí.
     *
     * Los tres métodos verificaban la contraseña y no miraban de quién era. Dos
     * de ellos se rompían solos —su `switch` por tipo no tiene rama para Alumno
     * ni Acudiente, así que reventaban con «Undefined array key 0» en un 500—, y
     * el tercero no tiene `switch` y respondía 200. Se cerraron los tres.
     */
    public function test_un_alumno_o_un_acudiente_no_entra_al_lector(): void
    {
        $rutas = [
            'tardanzas/login',
            'tardanzas/login/traer-datos',
            'tardanzas/login/traer-datos-ausencias',
        ];

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $credenciales = $this->credencialesDe($tipo);

            foreach ($rutas as $ruta) {
                $this->postJson('/api/'.$ruta, $credenciales)
                    ->assertStatus(403);
            }
        }
    }

    /**
     * El que de verdad entregaba algo, medido.
     *
     * `traer-datos-ausencias` devolvía a cualquier alumno —con su propia clave y
     * sin token— todas las ausencias y tardanzas del colegio: en la base de
     * desarrollo, 801 filas de 51 alumnos distintos. Y el año lo elige el
     * cuerpo, así que tampoco era «el suyo».
     *
     * El test no fija el 801: fija que lo que sale para un alumno no es más que
     * lo suyo. Un número del seed se rompería al regenerarlo y no diría nada.
     */
    public function test_un_alumno_no_saca_las_ausencias_del_colegio(): void
    {
        $credenciales = $this->credencialesDe('Alumno');

        $ausencias = DB::selectOne('SELECT COUNT(*) c FROM ausencias WHERE deleted_at IS NULL');
        $this->assertGreaterThan(0, $ausencias->c,
            'Sin ausencias en la base este test pasaría diciendo que la puerta está cerrada.');

        foreach (DB::select('SELECT id FROM years WHERE deleted_at IS NULL') as $year) {
            $this->postJson('/api/tardanzas/login/traer-datos-ausencias',
                $credenciales + ['year_id' => $year->id])->assertStatus(403);
        }
    }

    /** Y el personal las sigue sacando, que es para lo que existe la ruta. */
    public function test_el_personal_sigue_sacando_las_ausencias(): void
    {
        $r = $this->postJson('/api/tardanzas/login/traer-datos-ausencias',
            $this->credencialesDe('Usuario'));

        $r->assertStatus(200);
        $this->assertIsArray($r->json());
    }

    /**
     * La contraseña en claro ya no es una credencial.
     *
     * El respaldo que se quitó comparaba la columna `password` con el texto
     * tecleado y, si acertaba, la hasheaba en su sitio y dejaba entrar. Se
     * comprueba escribiendo una fila sin hashear a propósito: dentro de la
     * transacción del test, y es la única forma de comprobar que la puerta ya no
     * está —el seed no tiene ninguna.
     */
    public function test_una_columna_sin_hashear_no_deja_entrar(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        DB::table('users')->where('id', $usuario->id)->update(['password' => 'enclaro123']);

        $this->postJson('/api/tardanzas/login', [
            'username' => $usuario->username,
            'password' => 'enclaro123',
        ])->assertStatus(400);

        $this->assertSame('enclaro123',
            DB::table('users')->where('id', $usuario->id)->value('password'),
            'El respaldo que se quitó reescribía la columna al pasar por aquí.');
    }

    /** Sin usuario es 401 y con usuario equivocado 400, que es como estaba. */
    public function test_los_dos_codigos_de_credenciales_no_cambian(): void
    {
        $this->postJson('/api/tardanzas/login', [])->assertStatus(401);

        $this->postJson('/api/tardanzas/login', [
            'username' => 'no-existe-nadie-asi',
            'password' => 'x',
        ])->assertStatus(400);
    }

    /**
     * Subir ausencias sigue exigiendo ser del colegio, y sigue siendo un 400.
     *
     * TSubirController ya lo comprobaba; se fija aquí porque es la mitad del
     * módulo y porque el 400 es deliberado: no se cambia a 403 una respuesta que
     * el lector ya recibe hoy en los dieciséis colegios.
     */
    public function test_subir_ausencias_exige_ser_del_colegio(): void
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $ausencia = [
            'alumno_id' => $alumno->id,
            'asignatura_id' => null,
            'cantidad_ausencia' => 1,
            'cantidad_tardanza' => 0,
            'entrada' => '07:00',
            'tipo' => 'Ausencia',
            'fecha_hora' => '2026-08-20 07:00:00',
            'periodo_id' => $periodo->id,
        ];

        $this->putJson('/api/tardanzas/subir/poner-ausencia',
            $this->credencialesDe('Alumno') + $ausencia)->assertStatus(400);

        $antes = DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c;

        $r = $this->putJson('/api/tardanzas/subir/poner-ausencia',
            $this->credencialesDe('Profesor') + $ausencia);

        $r->assertStatus(200);
        $this->assertSame($antes + 1, DB::selectOne('SELECT COUNT(*) c FROM ausencias')->c,
            'La ausencia del profesor no llegó a escribirse.');
        $this->assertSame((int) $alumno->id, $r->json('alumno_id'));
    }
}
