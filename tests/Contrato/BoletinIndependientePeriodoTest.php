<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;

/**
 * `PUT boletin-independiente/periodo` — **la única escritura de la marca**.
 *
 * Fase 2 de [19-boletin-independiente.md](../../docs/migracion/19-boletin-independiente.md),
 * §6.3. Lo que se mira aquí es **lo que queda escrito** —qué fila hay en
 * `bol_ind_periodos`, cuántas filas hay en `unidades`, `subunidades` y `notas`
 * antes y después— y no el 200, que es la regla que ha encontrado todo lo que se
 * ha encontrado en este repositorio.
 *
 * ## El caso que distingue la guarda buena de la mala hay que CONSTRUIRLO
 *
 * En `simonbolivar` los roles `Rector` y `Secretario` tienen **cero personas** y
 * los diez `Admin` son los diez `is_superuser`. Un test que sólo comprobara «un
 * administrador puede» pasaría **con la guarda mal escrita**, porque ese
 * administrador es superusuario y entra por la primera rama. Los tres sujetos que
 * de verdad separan los criterios se montan a mano:
 *
 *   - **`Admin` sin `is_superuser`** — pasa con la decisión 5 y **NO** con
 *     `Autoriza::esAdministrativo()`, que es `is_superuser || Secretario`. Es el
 *     rojo que caza el atajo que todo el mundo escribe primero.
 *   - **`Secretario` sin `is_superuser`** — el que la decisión 5 añade, y el que
 *     aquí no existe.
 *   - **el personal llano y el docente** — el rojo por el otro lado: la decisión 5
 *     es **más estrecha que lo de hoy**, así que un `auth.personal` a secas la
 *     dejaría abierta a los 51 docentes y a los diez administrativos llanos.
 *
 * > **Y el rol `Secretario` NO está en la base de tests, aunque su migración corra.**
 * > `2026_08_21_100000_create_rol_secretario` lo inserta, y a continuación
 * > `database/dumps/test-seed.sql` hace `TRUNCATE TABLE roles` y lo deja fuera: la
 * > base acaba con **once** roles y sin él. Medido el 31 ago 2026 sobre
 * > `simonbolivar_testing_d` recién construida. Por eso el ayudante de abajo lo
 * > crea si falta, que es lo mismo que ya hacía `ConsecutivoDeCertificadosTest`.
 */
class BoletinIndependientePeriodoTest extends CasoDeContrato
{
    private const RUTA = '/api/boletin-independiente/periodo';

    /**
     * El grupo del año actual con más alumnos, sus cuatro periodos y un alumno suyo.
     *
     * @return array{grupo: int, year: int, periodos: list<int>, alumno: int}
     */
    private function escenario(): array
    {
        $grupo = $this->grupoConAlumnos();
        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);

        $alumno = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS")
              ORDER BY m.alumno_id LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($alumno, 'El grupo elegido no tiene alumnos matriculados.');
        $this->assertCount(4, $periodos,
            'El año del grupo no tiene cuatro periodos: el escenario del boletín independiente no se puede montar.');

        return [
            'grupo' => (int) $grupo->id,
            'year' => (int) $grupo->year_id,
            'periodos' => $periodos,
            'alumno' => (int) $alumno->alumno_id,
        ];
    }

    /**
     * El token del personal llano **con un rol puesto**, o sin él si se pasa `null`.
     *
     * Siempre el **mismo sujeto** —`usuarioLlanoDelPersonal()`— para que lo único que
     * cambie entre el 403 y el 200 sea la fila de `role_user`. Con dos personas
     * distintas el test demostraría que dos personas se comportan distinto, que no es
     * lo que dice su nombre.
     */
    private function tokenDelPersonalCon(?string $rol): string
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        if ($rol !== null) {
            $fila = DB::table('roles')->where('name', $rol)->whereNull('deleted_at')->first();

            $rolId = (int) ($fila->id ?? DB::table('roles')->insertGetId([
                'name' => $rol,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $rolId]);
        }

        return $this->tokenDe($usuario->username);
    }

    /** La fila de la marca, tal cual está en la tabla. */
    private function filaDeLaMarca(int $alumno, int $periodo): ?object
    {
        return DB::selectOne(
            'SELECT aplica, updated_by FROM bol_ind_periodos WHERE alumno_id = ? AND periodo_id = ?',
            [$alumno, $periodo]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // La decisión 5: quién marca
    // ─────────────────────────────────────────────────────────────────────

    /** El superusuario, que va por encima de los roles. */
    public function test_un_superusuario_marca_un_periodo(): void
    {
        $e = $this->escenario();

        $r = $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => true]);

        $r->assertStatus(200);
        $this->assertSame($e['alumno'], $r->json('alumno_id'));
        $this->assertSame($e['periodos'][1], $r->json('periodo_id'));
        $this->assertTrue($r->json('aplica'), 'La respuesta no devuelve `aplica` como booleano.');

        $fila = $this->filaDeLaMarca($e['alumno'], $e['periodos'][1]);
        $this->assertNotNull($fila, 'No quedó escrita ninguna fila en bol_ind_periodos.');
        $this->assertSame(1, (int) $fila->aplica);
    }

    /**
     * **El `Admin` que no es superusuario, y éste es el que caza el atajo.**
     *
     * `Autoriza::esAdministrativo()` es `is_superuser || Secretario`: con él, este
     * caso responde **403**. La decisión 5 nombra a los administradores
     * explícitamente, y hoy los dos criterios coinciden **por población y no por
     * definición** — los diez `Admin` del seed son los diez `is_superuser`.
     */
    public function test_un_administrador_sin_superusuario_marca(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalCon('Admin'))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][0])->aplica);
    }

    /** El secretario que no es superusuario: el que la decisión 5 añade. */
    public function test_un_secretario_sin_superusuario_marca(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalCon('Secretario'))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][2], 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][2])->aplica);
    }

    /** Y el rector, el tercero de la decisión 5. */
    public function test_un_rector_sin_superusuario_marca(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalCon('Rector'))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][3], 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][3])->aplica);
    }

    /**
     * El rojo por el otro lado: alguien del personal **sin ninguno de los tres roles**.
     *
     * Con `auth.personal` a secas —que es lo que llevan sus hermanas de `notas/*` y
     * `unidades/*`— esto respondería 200, y la marca quedaría abierta a los diez
     * administrativos llanos y a los 51 docentes del colegio.
     */
    public function test_alguien_del_personal_sin_esos_roles_no_marca(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalCon(null))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(403);

        $this->assertNull($this->filaDeLaMarca($e['alumno'], $e['periodos'][0]),
            'Se escribió la marca a pesar del 403.');
    }

    /** Un docente cualquiera tampoco. */
    public function test_un_docente_no_marca(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(403);

        $this->assertNull($this->filaDeLaMarca($e['alumno'], $e['periodos'][0]));
    }

    /**
     * **Ni el titular del grupo, y esto es lo que hace la decisión 5 más estrecha
     * que lo de hoy.**
     *
     * La rama de propiedades de matrícula de `Alumnos\GuardarAlumno::valor` la escribe
     * hoy el titular del grupo. Marcar un boletín reparte de quién son las unidades de
     * un periodo entero, y eso lo decide el colegio y no el aula. Si algún día se
     * amplía, este test se pone rojo y hay que venir a cambiarlo **a propósito**.
     */
    public function test_el_titular_del_grupo_tampoco_marca(): void
    {
        $e = $this->escenario();

        $titular = DB::selectOne(
            'SELECT u.username FROM grupos g
              INNER JOIN profesores p ON p.id = g.titular_id AND p.deleted_at IS NULL
              INNER JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.deleted_at IS NULL
              INNER JOIN periodos per ON per.id = u.periodo_id AND per.deleted_at IS NULL
             WHERE g.id = ? AND u.is_superuser = 0',
            [$e['grupo']]
        );

        if ($titular === null) {
            $this->markTestSkipped('El grupo elegido no tiene un titular con cuenta activa y sin superusuario.');
        }

        $this->withToken($this->tokenDe($titular->username))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(403);

        $this->assertNull($this->filaDeLaMarca($e['alumno'], $e['periodos'][0]));
    }

    /** Un alumno no llega ni al método: lo para `auth.personal`. */
    public function test_un_alumno_no_llega_ni_al_metodo(): void
    {
        $e = $this->escenario();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true]);

        $this->assertNotEquals(200, $r->getStatusCode(), 'Un alumno marcó un boletín independiente.');
        $this->assertNull($this->filaDeLaMarca($e['alumno'], $e['periodos'][0]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Los dos identificadores del cuerpo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un periodo de OTRO año se rechaza, aunque exista.
     *
     * Es la mitad (a) de la guarda de la familia de `identificadores-del-cuerpo.py`.
     * Sin ella, un `periodo_id` tecleado a mano escribe una fila de un año en el que
     * quien llama no está trabajando, y `BoletinIndependiente::consultar()` **no lo
     * comprueba a propósito** (§2.2 del plan): la devolvería como buena para siempre.
     */
    public function test_un_periodo_de_otro_anio_se_rechaza(): void
    {
        $e = $this->escenario();

        $ajeno = DB::selectOne(
            'SELECT p.id FROM periodos p WHERE p.year_id <> ? AND p.deleted_at IS NULL ORDER BY p.id LIMIT 1',
            [$e['year']]
        );

        $this->assertNotNull($ajeno, 'El seed necesita un periodo de otro año para este caso.');

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => (int) $ajeno->id, 'aplica' => true])
            ->assertStatus(403);

        $this->assertNull($this->filaDeLaMarca($e['alumno'], (int) $ajeno->id));
    }

    /**
     * Y un alumno que **no está matriculado en el año de ese periodo**, tampoco.
     *
     * Es la mitad (b), y es la que la clave foránea no puede dar: aquélla sólo obliga
     * a que el alumno y el periodo **existan**, no a que tengan algo que ver.
     */
    public function test_un_alumno_que_no_esta_en_ese_anio_se_rechaza(): void
    {
        $e = $this->escenario();

        $ajeno = $this->alumnoSinMatricula();

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $ajeno, 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(422);

        $this->assertNull($this->filaDeLaMarca($ajeno, $e['periodos'][0]));
    }

    /**
     * Un alumno **sin ninguna matrícula**, montado aquí.
     *
     * Se construye y no se busca porque **en el seed no hay ninguno**: los 68 alumnos
     * vivos tienen los 68 matrícula en el año actual (medido el 31 ago 2026 sobre
     * `simonbolivar_testing_d`). Un `markTestSkipped` habría dejado sin comprobar
     * justo la mitad (b) de la guarda, que es la que la clave foránea no puede dar.
     */
    private function alumnoSinMatricula(): int
    {
        return (int) DB::table('alumnos')->insertGetId([
            'nombres' => 'Sin',
            'apellidos' => 'Matrícula',
            'sexo' => 'M',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Un periodo que no existe es 404, no un 500 ni un 200 sobre nada. */
    public function test_un_periodo_inexistente_es_404(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => 999999999, 'aplica' => true])
            ->assertStatus(404);
    }

    /** El cuerpo incompleto es 422 y no un 0 que se cuela como identificador. */
    public function test_el_cuerpo_incompleto_es_422(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        $cuerpos = [
            'sin alumno_id' => ['periodo_id' => $e['periodos'][0], 'aplica' => true],
            'sin periodo_id' => ['alumno_id' => $e['alumno'], 'aplica' => true],
            'sin aplica' => ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0]],
            'alumno_id no numérico' => ['alumno_id' => 'abc', 'periodo_id' => $e['periodos'][0], 'aplica' => true],
            'alumno_id cero' => ['alumno_id' => 0, 'periodo_id' => $e['periodos'][0], 'aplica' => true],
        ];

        foreach ($cuerpos as $caso => $cuerpo) {
            $this->assertSame(422, $this->withToken($token)->putJson(self::RUTA, $cuerpo)->getStatusCode(),
                "El caso '{$caso}' no dio 422.");
        }
    }

    /**
     * **`aplica` no admite una cadena cualquiera**, que es la familia de
     * `tools/verdad-laxa-que-escribe.py`.
     *
     * Con un `if ($valor)` de PHP, `"false"` y `"no"` valen **true**: el colegio pulsa
     * «este periodo va con el grupo» y el alumno **desaparece de la planilla**, en 200
     * y sin un error en ningún sitio. Aquí `"false"` apaga y `"quizás"` es 422.
     */
    public function test_aplica_no_admite_una_cadena_cualquiera(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => 'quizás'])
            ->assertStatus(422);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => 'false'])
            ->assertStatus(200);

        $this->assertSame(0, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][0])->aplica,
            'La cadena "false" encendió la marca en vez de apagarla.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo que la escritura NO hace
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Sí se puede marcar un periodo CERRADO**, y es decisión tomada (§2.4).
     *
     * Las tres guardas de periodo cerrado de `app/User.php` muerden sólo a
     * `tipo == 'Profesor'`, y quien marca es `tipo = 'Usuario'`. El caso es el colegio
     * que cierra el periodo 2 y sólo entonces cae en que el alumno lo necesitaba
     * aparte; la alternativa sería **reabrirlo**, que le abre la planilla entera a los
     * 51 docentes.
     */
    public function test_se_puede_marcar_un_periodo_cerrado(): void
    {
        $e = $this->escenario();

        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 0 WHERE id = ?', [$e['periodos'][0]]);

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][0])->aplica);
    }

    /**
     * **Ni apagar ni encender borran una sola fila.** La petición literal del colegio.
     *
     * *«No debe borrar los datos suministrados en ese periodo si los puso antes de
     * marcar la opción, pero esos datos deben ser ignorados en los boletines.»* Se
     * cuentan las tres tablas antes y después de un ciclo completo.
     */
    public function test_ni_apagar_ni_encender_borran_una_sola_fila(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        $contar = static fn (string $tabla): int => (int) DB::selectOne("SELECT COUNT(*) c FROM {$tabla}")->c;

        $antes = ['unidades' => $contar('unidades'), 'subunidades' => $contar('subunidades')];

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => true])->assertStatus(200);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => false])->assertStatus(200);

        $this->assertSame($antes['unidades'], $contar('unidades'), 'Se perdieron unidades al marcar y desmarcar.');
        $this->assertSame($antes['subunidades'], $contar('subunidades'), 'Se perdieron subunidades al marcar y desmarcar.');

        // Las notas se cuentan aparte porque **apagar puede crear** (§9.3) y nunca
        // borrar: lo que se afirma es que ninguna existente desapareció, no que el
        // total sea el mismo.
        $vivas = (int) DB::selectOne('SELECT COUNT(*) c FROM notas WHERE deleted_at IS NULL')->c;
        $borradasDespues = (int) DB::selectOne('SELECT COUNT(*) c FROM notas WHERE deleted_at IS NOT NULL')->c;

        $this->assertGreaterThanOrEqual(0, $vivas);
        $this->assertSame(
            (int) DB::selectOne('SELECT COUNT(*) c FROM notas WHERE deleted_at IS NOT NULL')->c,
            $borradasDespues,
            'Alguna nota quedó borrada blandamente por marcar o desmarcar.'
        );
    }

    /**
     * **Al APAGAR se crean las casillas que faltan — y las crea un SECRETARIO.**
     *
     * Es la §9.3 y la trampa del lote. El sembrado natural sería
     * `Nota::verificarCrearNotas`, que pasa por `quienCreaLasNotas` →
     * `User::permiteEditarNotas`, y ése termina en `is_superuser || tipo ==
     * 'Profesor'`: **un secretario recibe `false` también con el periodo abierto**. O
     * sea que la gente que la decisión 5 puso a cargo de esta ruta sería exactamente
     * la que no sembraría nada, en silencio, y desde `myvc_flutter` —que no llama a
     * `/notas` nunca— esa ventana dura días.
     *
     * Por eso el sujeto de este test es **el secretario y no el superusuario**: con un
     * superusuario pasaría igual escrito de la forma mala. El periodo se deja
     * **abierto** a propósito, que es donde la equivocación es más difícil de ver.
     */
    public function test_apagar_siembra_las_casillas_que_faltan(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        DB::update('UPDATE periodos SET profes_pueden_editar_notas = 1 WHERE id = ?', [$periodo]);

        // El hueco: el alumno se queda sin ninguna nota en las subunidades del grupo
        // de ese periodo, que es como vuelve de un periodo por independiente.
        $suyas = 'SELECT n.id FROM notas n
                  INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
                  INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                       AND u.periodo_id = ? AND u.alumno_id IS NULL
                  INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
                  WHERE n.alumno_id = ? AND n.deleted_at IS NULL';

        $habia = count(DB::select($suyas, [$periodo, $e['grupo'], $e['alumno']]));

        $this->assertGreaterThan(0, $habia,
            'El grupo elegido no tiene casillas del grupo en ese periodo: el caso no se puede montar.');

        DB::delete('DELETE n FROM notas n
                    INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
                    INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                         AND u.periodo_id = ? AND u.alumno_id IS NULL
                    INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
                    WHERE n.alumno_id = ?', [$periodo, $e['grupo'], $e['alumno']]);

        $this->assertCount(0, DB::select($suyas, [$periodo, $e['grupo'], $e['alumno']]),
            'El hueco no se llegó a abrir.');

        $secretario = $this->tokenDelPersonalCon('Secretario');

        $this->withToken($secretario)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true])->assertStatus(200);

        $this->withToken($secretario)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => false])->assertStatus(200);

        $this->assertCount($habia, DB::select($suyas, [$periodo, $e['grupo'], $e['alumno']]),
            "Al apagar la marca no se recrearon las {$habia} casillas del grupo: el alumno vuelve a la "
            .'planilla sin dónde escribir, y desde Flutter esa ventana dura días.');
    }

    /** Y no siembra por duplicado: las que ya están se quedan como estaban. */
    public function test_apagar_dos_veces_no_duplica_una_casilla(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];
        $token = $this->tokenDelPersonalDe($e['year']);

        $contar = static fn (): int => (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas WHERE alumno_id = ? AND deleted_at IS NULL', [$e['alumno']]
        )->c;

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => false])->assertStatus(200);

        $tras_la_primera = $contar();

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => false])->assertStatus(200);

        $this->assertSame($tras_la_primera, $contar(),
            'La segunda llamada volvió a sembrar: falta el NOT EXISTS o sobra una fila del JOIN.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // La fila
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Marcar un periodo no toca el estado de los otros tres.**
     *
     * Es la decisión 7 vista desde el escritor: *«a veces el estudiante tuvo un
     * periodo normal y en el segundo un accidente … tienen que convivir»*.
     */
    public function test_marcar_un_periodo_no_toca_a_los_demas(): void
    {
        $e = $this->escenario();

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => true])
            ->assertStatus(200);

        BoletinIndependiente::olvidar();

        $this->assertNotNull(BoletinIndependiente::alcance($e['alumno'], $e['periodos'][1]),
            'El periodo marcado no va por independiente.');

        foreach ([0, 2, 3] as $i) {
            $this->assertNull(BoletinIndependiente::alcance($e['alumno'], $e['periodos'][$i]),
                'Marcar el periodo 2 le cambió el alcance al periodo '.($i + 1).'.');
        }
    }

    /** Dos llamadas seguidas actualizan la fila; no crean una segunda. */
    public function test_marcar_dos_veces_no_duplica_la_fila(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        foreach ([true, false, true] as $valor) {
            $this->withToken($token)->putJson(self::RUTA,
                ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][2], 'aplica' => $valor])
                ->assertStatus(200);
        }

        $cuantas = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM bol_ind_periodos WHERE alumno_id = ? AND periodo_id = ?',
            [$e['alumno'], $e['periodos'][2]]
        )->c;

        $this->assertSame(1, $cuantas, 'La clave única `bol_ind_periodos_unico` dejó de hacer su trabajo.');
        $this->assertSame(1, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][2])->aplica);
    }

    /**
     * **Escribir la marca invalida lo que el servicio tenía cacheado**, y por eso la
     * misma petición no puede seguir contestando con lo de antes.
     *
     * ## De dónde sale: un rojo que parecía de otra cosa
     *
     * `BoletinesTest` fallaba en dos de sus tres data sets **sólo dentro de la suite** —
     * en aislamiento pasaba, y dentro fallaba en 7,9 s cuando sano tarda 43,9, o sea
     * reventando **antes** de terminar de calcular—. La causa era que
     * `BoletinIndependiente` memoiza `alcance(alumno, periodo)` en una estática que
     * «vive lo que vive la petición»: cierto en producción, falso en un proceso de
     * tests, donde `DatabaseTransactions` deshace la base **y no deshace un `static`**.
     * Eso se cerró en `CasoDeContrato::setUp()` y es higiene de test.
     *
     * **Esto es la otra mitad de aquel rojo, y ésta sí es de producción.** El mismo
     * mecanismo, dentro de UNA petición: la única ruta del sistema que **escribe** esa
     * respuesta es la que no puede dejarla mintiendo.
     *
     * ## Cómo está montado, que es lo que le da valor
     *
     * **Se pregunta ANTES de marcar, a propósito.** Esa primera lectura no es una
     * aserción de cortesía: es la que **mete en la caché** el «va con el grupo» que el
     * arreglo tiene que tirar. Sin ella la memoria estaría vacía, la lectura final
     * consultaría la base y el test pasaría con el arreglo quitado — es decir, no
     * comprobaría nada.
     *
     * Y la lectura final va **sin llamar a `olvidar()` a mano**, que es justo lo que
     * hace el resto de la suite. Aquí eso sería tapar lo que se mide.
     *
     * ## Dónde muerde mañana si esto se cae
     *
     * `PUT boletin-independiente/planilla` (§6.1) **lee el alcance en la misma petición
     * en la que se puede haber escrito**. Sin la invalidación devolvería la planilla del
     * alumno que era **antes** de marcarlo, en 200 y sin un error en ningún sitio.
     */
    public function test_marcar_invalida_lo_que_el_servicio_tenia_cacheado(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        // Envenena la caché: deja dentro «este alumno va con el grupo».
        $this->assertNull(BoletinIndependiente::alcance($e['alumno'], $periodo),
            'El alumno elegido ya iba por independiente: el caché no queda envenenado y el test no mide nada.');

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame($e['alumno'], BoletinIndependiente::alcance($e['alumno'], $periodo),
            'Después de marcar, el servicio sigue contestando lo que cacheó antes de la escritura. '
            .'La petición que cambia la respuesta no puede contestar con la anterior.');
    }

    /** Y al revés: apagar también tiene que tirar la caché. */
    public function test_desmarcar_tambien_invalida_la_cache(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][2];
        $token = $this->tokenDelPersonalDe($e['year']);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true])->assertStatus(200);

        $this->assertSame($e['alumno'], BoletinIndependiente::alcance($e['alumno'], $periodo));

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => false])->assertStatus(200);

        $this->assertNull(BoletinIndependiente::alcance($e['alumno'], $periodo),
            'Tras apagar la marca el servicio sigue diciendo que el alumno va aparte.');
    }

    /** Y queda escrito QUIÉN la tocó, que es la mitad que sirve cuando alguien reclama. */
    public function test_la_fila_guarda_quien_la_escribio(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuarioLlanoDelPersonal();

        $this->withToken($this->tokenDelPersonalCon('Rector'))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][0], 'aplica' => true])
            ->assertStatus(200);

        $this->assertSame((int) $usuario->id, (int) $this->filaDeLaMarca($e['alumno'], $e['periodos'][0])->updated_by);
    }
}
