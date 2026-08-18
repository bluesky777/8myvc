<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Quién puede hacer qué, una vez presentado un token válido.
 *
 * Tener token prueba que eres alguien, no que puedas ver lo que pides. Este
 * archivo cubre las cuatro guardas de autorización que existían escritas en el
 * código y **no se ejecutaban ninguna**. No hizo falta deducirlo: se comprobó
 * golpeando los endpoints con un token de alumno de la semilla.
 *
 * Ver docs/migracion/06-autorizacion.md.
 */
class AutorizacionTest extends CasoDeContrato
{
    /** Los tres controladores de boletines son copias y sirven el mismo dato. */
    public function familiasDeBoletines(): array
    {
        return [
            'boletines'  => ['boletines'],
            'boletines2' => ['boletines2'],
            'boletines3' => ['boletines3'],
        ];
    }

    /** El alumno y su grupo, más un compañero suyo que no es él. */
    private function alumnoYCompanero(): array
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $mio = DB::select('SELECT a.id alumno_id, m.grupo_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id
                AND m.estado IN ("MATR","ASIS","PREM") AND m.deleted_at IS NULL
            WHERE a.user_id = ? AND a.deleted_at IS NULL LIMIT 1', [$usuario->id])[0];

        $otro = DB::select('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.id <> ? AND a.deleted_at IS NULL LIMIT 1',
            [$mio->grupo_id, $mio->alumno_id])[0];

        return [$this->tokenDe($usuario->username), $mio, $otro];
    }

    private function pedir(string $token, string $familia, int $grupoId, ?array $alumno)
    {
        $cuerpo = $alumno === null ? [] : ['requested_alumnos' => [$alumno]];

        return $this->putJson("/api/{$familia}/detailed-notas/{$grupoId}", $cuerpo,
            ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * El agujero que motiva todo esto.
     *
     * `abort()` lanza una HttpException, que es un Throwable, así que el
     * `catch (\Throwable $th)` del constructor de BoletinesController se la
     * tragaba entera. Respondía 200 con el boletín del compañero.
     *
     * @dataProvider familiasDeBoletines
     */
    public function test_un_alumno_no_puede_pedir_el_boletin_de_otro(string $familia): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();

        $this->pedir($token, $familia, $mio->grupo_id,
            ['alumno_id' => $otro->alumno_id, 'matricula_id' => $otro->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');
    }

    /** @dataProvider familiasDeBoletines */
    public function test_un_alumno_si_puede_pedir_el_suyo(string $familia): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($token, $familia, $mio->grupo_id,
            ['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'La guarda rechaza a un alumno pidiendo su propio boletín.');
    }

    /**
     * El alumno recibe su boletín, y solo el suyo.
     *
     * Va contra `boletines/detailed-notas`, que es la ruta del flujo real: el
     * estado `panel.boletin_acudiente` de `myvc_front` es el que usan alumno y
     * acudiente, y llama a esa. Las variantes 2 y 3 son maquetas para el personal.
     *
     * **El payload es el que manda el frontend**: `[{alumno_id, grupo_id}]`, sin
     * `matricula_id` (`NotasAlumnoCtrl.js`, `verMiBoletin()` y `verBoletin()`).
     * Importa que sea ese y no uno cómodo: con `matricula_id` el endpoint
     * respondía bien y sin él respondía 500 desde 2021, así que un test con el
     * payload inventado habría dado verde sobre una pantalla rota.
     */
    public function test_el_alumno_recibe_su_boletin_y_solo_el_suyo(): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $r = $this->pedir($token, 'boletines', $mio->grupo_id,
            ['alumno_id' => $mio->alumno_id, 'grupo_id' => $mio->grupo_id]);

        $r->assertStatus(200);

        // La respuesta es [grupo, year, alumnos, escalas].
        $alumnos = $r->json('2');

        $this->assertCount(1, $alumnos,
            'Pidió su boletín y le devolvieron ' . count($alumnos) . ' alumnos.');

        $this->assertSame($mio->alumno_id, $alumnos[0]['alumno_id']);
    }

    /**
     * Sin lista concreta se está pidiendo el grupo entero, que es lo que hace
     * `detailed-notas-year`. Un alumno no puede.
     *
     * @dataProvider familiasDeBoletines
     */
    public function test_un_alumno_no_puede_pedir_el_grupo_entero(string $familia): void
    {
        [$token, $mio] = $this->alumnoYCompanero();

        $this->pedir($token, $familia, $mio->grupo_id, null)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Pedis más de lo que debes');

        $this->getJson("/api/{$familia}/detailed-notas-year/{$mio->grupo_id}",
            ['Authorization' => 'Bearer ' . $token])->assertStatus(403);
    }

    /** Un profesor sigue viendo el grupo entero: la guarda no le aplica. */
    public function test_un_profesor_sigue_viendo_el_grupo_entero(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        [, $mio]  = $this->alumnoYCompanero();

        $r = $this->pedir($this->tokenDe($profesor->username), 'boletines', $mio->grupo_id, null);

        $this->assertNotSame(403, $r->getStatusCode(),
            'La guarda de boletín propio está rechazando al personal del colegio.');
    }

    private function acudienteConAcudido(): array
    {
        $fila = DB::select('SELECT u.username, ac.id acudiente_id, p.alumno_id, m.grupo_id, m.id matricula_id
            FROM users u
            INNER JOIN acudientes ac ON ac.user_id = u.id AND ac.deleted_at IS NULL
            INNER JOIN parentescos p ON p.acudiente_id = ac.id AND p.deleted_at IS NULL
            INNER JOIN matriculas m ON m.alumno_id = p.alumno_id AND m.deleted_at IS NULL
            INNER JOIN periodos per ON per.id = u.periodo_id
            WHERE u.tipo = "Acudiente" AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotEmpty($fila, 'La semilla no tiene ningún acudiente con parentesco y matrícula.');

        return [$this->tokenDe($fila[0]->username), $fila[0]];
    }

    public function test_un_acudiente_no_puede_pedir_el_de_quien_no_es_su_acudido(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        $ajeno = DB::select('SELECT a.id alumno_id, m.id matricula_id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE a.id <> ? AND a.deleted_at IS NULL LIMIT 1', [$suyo->alumno_id])[0];

        $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $ajeno->alumno_id, 'matricula_id' => $ajeno->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No es acudiente de este alumno. Lo siento.');
    }

    public function test_un_acudiente_si_puede_pedir_el_de_su_acudido(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        DB::update('UPDATE alumnos SET pazysalvo = 1 WHERE id = ?', [$suyo->alumno_id]);

        $r = $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $suyo->alumno_id, 'matricula_id' => $suyo->matricula_id]);

        $this->assertNotSame(403, $r->getStatusCode(),
            'La guarda rechaza a un acudiente pidiendo el boletín de su acudido.');
    }

    /**
     * El paz y salvo vuelve a decidir.
     *
     * Estaba escrito y no se aplicaba: cualquier acudiente con deuda veía el
     * boletín igual. Joseth pidió activarlo (18 ago 2026) sabiendo que se nota
     * en producción desde el despliegue.
     */
    public function test_un_acudiente_con_deuda_no_ve_el_boletin(): void
    {
        [$token, $suyo] = $this->acudienteConAcudido();

        DB::update('UPDATE alumnos SET pazysalvo = 0 WHERE id = ?', [$suyo->alumno_id]);

        $this->pedir($token, 'boletines', $suyo->grupo_id,
            ['alumno_id' => $suyo->alumno_id, 'matricula_id' => $suyo->matricula_id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No está a paz y salvo. Lo siento.');
    }

    /** El rechazo deja rastro: es lo que mira el colegio cuando alguien reclama. */
    public function test_el_intento_rechazado_queda_en_bitacoras(): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();

        $antes = DB::table('bitacoras')->where('affected_element_type', 'AlumnoVerBoletin')->count();

        $this->pedir($token, 'boletines', $mio->grupo_id,
            ['alumno_id' => $otro->alumno_id, 'matricula_id' => $otro->matricula_id])
            ->assertStatus(403);

        $this->assertSame($antes + 1,
            DB::table('bitacoras')->where('affected_element_type', 'AlumnoVerBoletin')->count(),
            'El intento de ver el boletín de otro no quedó registrado.');
    }

    /**
     * La otra forma de pedir un alumno.
     *
     * `certificados-persona` no manda `requested_alumnos` sino un `alumno_id`
     * suelto. Es la misma familia —devuelve las matrículas de esa persona— y el
     * middleware entiende las dos formas; si solo entendiera una, esta ruta
     * seguiría abierta y el arreglo de los boletines no serviría de nada.
     */
    public function test_certificados_persona_solo_del_alumno_propio(): void
    {
        [$token, $mio, $otro] = $this->alumnoYCompanero();
        $cab = ['Authorization' => 'Bearer ' . $token];

        $this->putJson('/api/certificados-persona', ['alumno_id' => $otro->alumno_id], $cab)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes ver el de otros');

        $this->assertNotSame(403,
            $this->putJson('/api/certificados-persona', ['alumno_id' => $mio->alumno_id], $cab)->getStatusCode(),
            'La guarda rechaza a un alumno pidiendo sus propias matrículas.');
    }

    /**
     * `requisitos` y `piars-grupos` los podía usar cualquiera con token.
     *
     * Un alumno llegaba a `DELETE api/requisitos/destroy/{id}` y recibía 200
     * "Eliminado". El único intento de guarda era un `return 'No tienes
     * permiso';` dentro de un constructor, que no detiene nada.
     */
    public function test_un_alumno_no_entra_en_lo_del_personal(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);
        $cab   = ['Authorization' => 'Bearer ' . $token];

        $this->putJson('/api/requisitos', [], $cab)->assertStatus(403);
        $this->deleteJson('/api/requisitos/destroy/999999', [], $cab)->assertStatus(403);
        $this->getJson('/api/piars-grupos/grupos', $cab)->assertStatus(403);
    }

    /** Y el personal sin superusuario sigue entrando: era el riesgo del cambio. */
    public function test_el_personal_sin_superusuario_sigue_entrando(): void
    {
        $fila = DB::select('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotEmpty($fila, 'La semilla no tiene ningún Usuario sin superusuario.');

        $cab = ['Authorization' => 'Bearer ' . $this->tokenDe($fila[0]->username)];

        $this->putJson('/api/requisitos', [], $cab)->assertStatus(200);
        $this->getJson('/api/piars-grupos/grupos', $cab)->assertStatus(200);
    }

    /**
     * Qué rutas llevan cada guarda de autorización.
     *
     * Sin esto, quitar un `->middleware(...)` de una ruta no rompería nada: los
     * tests de arriba solo miran las rutas que nombran, y el agujero original
     * era precisamente que la copia de al lado no tenía la comprobación.
     */
    public function test_las_guardas_estan_en_las_rutas_que_deben(): void
    {
        $esperado = [
            'auth.personal' => [
                'DELETE api/boletines2/destroy/{id}',
                'DELETE api/boletines3/destroy/{id}',
                'DELETE api/requisitos/destroy/{id}',
                'GET api/piars-grupos/contexto-de-grupo/{grupo_id}',
                'GET api/piars-grupos/grupos',
                'POST api/requisitos/alumno',
                'POST api/requisitos/store',
                'PUT api/piars-grupos/contexto-de-grupo',
                'PUT api/prematriculas/alumnos-con-grado-anterior',
                'PUT api/prematriculas/alumnos-grado-anterior',
                'PUT api/prematriculas/llevo-formulario',
                'PUT api/requisitos',
                'PUT api/requisitos/listado-observaciones',
                'PUT api/requisitos/update',
            ],
            'boletin.propio' => [
                'GET api/boletines/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}',
                'GET api/boletines2/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}',
                'GET api/boletines3/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}',
                'PUT api/boletines/detailed-notas-group/{grupo_id}',
                'PUT api/boletines/detailed-notas/{grupo_id}',
                'PUT api/boletines2/detailed-notas-group/{grupo_id}',
                'PUT api/boletines2/detailed-notas/{grupo_id}',
                'PUT api/boletines3/detailed-notas-group/{grupo_id}',
                'PUT api/boletines3/detailed-notas/{grupo_id}',
                'PUT api/bolfinales-preescolar/detailed-notas-year-group/{grupo_id}',
                'PUT api/bolfinales-preescolar/detailed-notas-year/{grupo_id}',
                'PUT api/bolfinales/detailed-notas-year-group/{grupo_id}',
                'PUT api/bolfinales/detailed-notas-year/{grupo_id}',
                'PUT api/certificados-persona',
                'PUT api/notas-actuales-alumnos/{grupo_id}',
            ],
        ];

        foreach ($esperado as $guarda => $rutas) {
            $reales = [];

            foreach (Route::getRoutes() as $ruta) {
                if (! in_array($guarda, $ruta->middleware(), true)) {
                    continue;
                }

                foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                    $reales[] = $verbo . ' ' . $ruta->uri();
                }
            }

            sort($reales);

            $this->assertSame($rutas, $reales, "Cambió la lista de rutas con '{$guarda}'.");
        }
    }
}
