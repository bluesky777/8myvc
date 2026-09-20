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
     * marcar la opción, pero esos datos deben ser ignorados en los boletines.»*
     *
     * ## Este caso cambió de FORMA con D18, y no de fondo
     *
     * Hasta el 13 sep 2026 afirmaba que **el total** de `unidades` y `subunidades` era el
     * mismo antes y después, lo cual era cierto **sólo porque marcar no creaba nada**.
     * Desde D18 marcar le siembra la rejilla del grupo a su nombre, así que el total
     * sube — y un caso que exigiera la igualdad estaría fijando la ausencia de la
     * función, no la promesa del colegio.
     *
     * Lo que se comprueba es lo que de verdad se prometió, que es más fuerte que el
     * total: **ninguna de las filas que existían antes desapareció ni quedó con
     * `deleted_at`**. Se guardan sus ids y se buscan una a una. Es exactamente lo que
     * este mismo caso ya hacía con `notas` desde que apagar empezó a sembrar.
     */
    public function test_ni_apagar_ni_encender_borran_una_sola_fila(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        $ids = static fn (string $tabla): array => array_map(
            static fn ($f) => (int) $f->id,
            DB::select("SELECT id FROM {$tabla} WHERE deleted_at IS NULL")
        );

        $antes = [
            'unidades' => $ids('unidades'),
            'subunidades' => $ids('subunidades'),
            'notas' => $ids('notas'),
        ];

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => true])->assertStatus(200);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][1], 'aplica' => false])->assertStatus(200);

        foreach ($antes as $tabla => $viejas) {
            $siguenVivas = array_flip($ids($tabla));
            $perdidas = array_values(array_filter($viejas, static fn (int $id) => ! isset($siguenVivas[$id])));

            $this->assertSame([], $perdidas,
                "Marcar y desmarcar se llevó por delante filas de {$tabla}: ".implode(', ', array_slice($perdidas, 0, 10)));
        }
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

    // ─────────────────────────────────────────────────────────────────────
    // MARCAR SIEMBRA — la Entrega 4, D18
    // ─────────────────────────────────────────────────────────────────────

    /** Cuántas unidades **propias** tiene el alumno en ese periodo, dentro de su grupo. */
    /**
     * **La casilla que se siembra nace SIN nota, no con el valor por defecto de su
     * subunidad.** Es la fase 0 del
     * [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md) llegando a las
     * dos siembras de este controlador, que eran las que faltaban: aquel censo dijo «las
     * tres siembras vivas» y son **cinco**.
     *
     * ## Por qué hacía falta un test nuevo y no valía ninguno de los de arriba
     *
     * Los demás comprueban **contadores** —`sembrado.subunidades`, `notas_traidas`— y
     * ninguno mira **el valor de la casilla**, así que los dos comportamientos —sembrar
     * `NULL` y sembrar `nota_default`— los dejan igual de verdes. El cambio del 20 sep
     * 2026 pasó la suite entera sin que nada lo cubriera; esto es lo que lo cubre.
     *
     * ## El valor distintivo no es adorno: sin él el test pasa con el bug puesto
     *
     * En la copia de desarrollo **el 88 % de las subunidades tienen `nota_default = 0`**, y
     * `assertEquals(0, null)` es **cierto** en PHP —comparación laxa—, que es exactamente
     * por lo que `NotasTest::test_abrir_la_rejilla_crea_las_notas_que_faltan` sigue en
     * verde desde la fase 0 sin comprobar nada. Poniendo un **41** antes de marcar, la
     * diferencia entre las dos versiones del código deja de poder disimularse: o la
     * casilla vale `null`, o vale 41.
     */
    public function test_la_casilla_sembrada_nace_sin_nota(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $this->assertSame(0, $this->suyasEn($e, $periodo), 'El alumno ya tenía estructura propia.');

        // Un valor que no se confunde con nada: ni con el 0 del defecto, ni con el null de
        // la ausencia. Va sobre la rejilla DEL CURSO, que es de donde se copia la suya.
        $tocadas = DB::update(
            'UPDATE subunidades s
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id IS NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ? AND a.deleted_at IS NULL
                SET s.nota_default = 41
              WHERE s.deleted_at IS NULL',
            [$periodo, $e['grupo']]
        );

        $this->assertGreaterThan(0, $tocadas,
            'No se pudo marcar ninguna subunidad del curso: el control de este test no existiría.');

        // **Y hay que dejarle huecos, o esto no prueba nada.** `copiarleA()` se trae la nota
        // que el alumno YA tenía en la subunidad del curso, y sólo lo que quede sin fila pasa
        // por `sembrarLasCasillasDeSusUnidades()`, que es la siembra que se está midiendo. En
        // el seed el alumno tiene nota en todas, así que sin esto `casillas_nuevas` sale **0**
        // y el test saldría verde midiendo una población vacía — pasó al escribirlo.
        $borradas = DB::update(
            'UPDATE notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id IS NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ? AND a.deleted_at IS NULL
                SET n.deleted_at = NOW()
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL',
            [$periodo, $e['grupo'], $e['alumno']]
        );

        $this->assertGreaterThan(0, $borradas,
            'El alumno no tenía ninguna nota en la rejilla del curso: no se le pueden abrir huecos.');

        $r = $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true]);

        $r->assertStatus(200);

        // El contador de LA siembra que se está midiendo, no el de subunidades copiadas.
        $this->assertGreaterThan(0, $r->json('sembrado.casillas_nuevas'),
            'No se sembró ninguna casilla nueva: sin eso, las dos cuentas de abajo miden una '
            .'población vacía y salen verdes con el bug puesto.');

        // La pregunta entera, en una consulta: ¿queda alguna casilla suya con el valor por
        // defecto dentro? Se cuenta sobre `notas`, que es donde se escribe, y no sobre la
        // respuesta, que es donde se cuenta.
        $conElDefecto = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id = ?
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL AND n.nota = 41',
            [$periodo, $e['alumno'], $e['alumno']]
        )->c;

        $this->assertSame(0, $conElDefecto,
            'Una casilla recién sembrada nació con el `nota_default` de su subunidad en vez de '
            .'`NULL`. Desde ese instante pesa en la definitiva sin que nadie la haya calificado '
            .'—es el bug de origen del 43— y además la cobertura de la Fase 2 la cuenta como '
            .'evaluada, así que la pantalla afirma que se evaluó todo justo donde no se evaluó nada.');

        // Y la otra mitad, que es la que impide que esto pase por estar vacío: que SÍ se
        // hayan creado casillas, y sin nota.
        $sinNota = (int) DB::selectOne(
            'SELECT COUNT(*) c FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id = ?
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL AND n.nota IS NULL',
            [$periodo, $e['alumno'], $e['alumno']]
        )->c;

        $this->assertGreaterThan(0, $sinNota,
            'No quedó ni una casilla suya sin nota: si no se creó ninguna, el cero de arriba no '
            .'demuestra nada. Un control que mide una población vacía sale verde siempre.');
    }

    private function suyasEn(array $e, int $periodo): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ? AND a.deleted_at IS NULL
              WHERE u.alumno_id = ? AND u.periodo_id = ? AND u.deleted_at IS NULL',
            [$e['grupo'], $e['alumno'], $periodo]
        )->c;
    }

    /** Cuántas unidades tiene el CURSO en ese periodo. */
    private function delCursoEn(array $e, int $periodo): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) c FROM unidades u
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ? AND a.deleted_at IS NULL
              WHERE u.alumno_id IS NULL AND u.periodo_id = ? AND u.deleted_at IS NULL',
            [$e['grupo'], $periodo]
        )->c;
    }

    /**
     * **Marcar le monta la rejilla del grupo a su nombre.** Es D18, y es lo que hace que
     * marcar deje de ser el principio del trabajo.
     *
     * Sin esto, marcar le dejaba la planilla **en blanco en sus trece asignaturas**: las
     * unidades se leen con alcance excluyente —`u.alumno_id <=> alcance`— y para un
     * marcado ese alcance es su propio id. Es la §9.1, el riesgo grave del documento.
     *
     * Se cuentan las filas escritas y **además** se comprueba que los contadores de la
     * respuesta dicen lo mismo: un recuento que no coincida con la base es peor que no
     * mandar ninguno, porque el colegio se lo cree.
     */
    public function test_marcar_le_siembra_la_rejilla_del_grupo(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $delCurso = $this->delCursoEn($e, $periodo);
        $this->assertGreaterThan(0, $delCurso,
            'El grupo elegido no tiene rejilla de curso en ese periodo: el caso no se puede montar.');
        $this->assertSame(0, $this->suyasEn($e, $periodo), 'El alumno ya tenía estructura propia.');

        $r = $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true]);

        $r->assertStatus(200);

        $this->assertSame($delCurso, $this->suyasEn($e, $periodo),
            'Marcar no le sembró la rejilla del grupo: se queda con la planilla en blanco en todas '
            .'sus asignaturas, que es la §9.1 y es el riesgo grave de este módulo.');

        $this->assertSame($delCurso, $r->json('sembrado.unidades'),
            'El contador de unidades no dice lo que hay en la base.');
        $this->assertGreaterThan(0, $r->json('sembrado.asignaturas_sembradas'));
        $this->assertGreaterThan(0, $r->json('sembrado.subunidades'));

        $this->assertSame(
            $r->json('sembrado.asignaturas_revisadas'),
            $r->json('sembrado.asignaturas_sembradas')
                + $r->json('sembrado.saltadas_porque_ya_tenia')
                + $r->json('sembrado.saltadas_sin_rejilla_del_grupo'),
            'Las tres primeras cifras no cuadran: revisadas tiene que ser sembradas más las dos saltadas.'
        );
    }

    /**
     * **Y se lleva las notas que ya tenía.** El caso de la §9.3 por la otra puerta: el
     * estudiante que iba en la planilla y se marca a mitad de periodo **no empieza en
     * blanco**.
     *
     * Se le pone un valor reconocible en una casilla del curso y se busca ese mismo valor
     * en una subunidad **suya** después de marcar. Contar notas no valdría: el sembrado
     * crea casillas con `nota_default` y el total subiría igual con las notas perdidas.
     */
    public function test_marcar_se_lleva_las_notas_que_ya_tenia(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $casilla = DB::selectOne(
            'SELECT n.id FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id IS NULL
               INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
              WHERE n.alumno_id = ? AND n.deleted_at IS NULL
              ORDER BY n.id LIMIT 1',
            [$periodo, $e['grupo'], $e['alumno']]
        );

        $this->assertNotNull($casilla, 'El alumno no tiene ninguna casilla del curso en ese periodo.');

        DB::update('UPDATE notas SET nota = 37 WHERE id = ?', [$casilla->id]);

        $r = $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true]);

        $r->assertStatus(200);

        $suya = DB::selectOne(
            'SELECT n.id FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
                                    AND u.periodo_id = ? AND u.alumno_id = ?
              WHERE n.alumno_id = ? AND n.nota = 37 AND n.deleted_at IS NULL
              LIMIT 1',
            [$periodo, $e['alumno'], $e['alumno']]
        );

        $this->assertNotNull($suya,
            'La nota que el alumno ya tenía no viajó a su rejilla propia: marcar le borra el trabajo '
            .'del periodo de la vista, aunque la fila vieja siga ahí.');

        $this->assertGreaterThan(0, $r->json('sembrado.notas_traidas'));
    }

    /**
     * **Marcar dos veces no le duplica la rejilla.** La pantalla no sabe si ya estaba
     * marcado, así que esto pasa de verdad: con `reemplazar` en vez de `saltar`, la
     * segunda llamada le dejaría la suma al doble y en 200.
     */
    public function test_marcar_dos_veces_no_le_duplica_la_rejilla(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];
        $token = $this->tokenDelPersonalDe($e['year']);

        $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true])->assertStatus(200);

        $tras_la_primera = $this->suyasEn($e, $periodo);
        $this->assertGreaterThan(0, $tras_la_primera);

        $r = $this->withToken($token)->putJson(self::RUTA,
            ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true]);

        $r->assertStatus(200);

        $this->assertSame($tras_la_primera, $this->suyasEn($e, $periodo),
            'La segunda llamada volvió a sembrar y le dobló el reparto.');

        $this->assertSame(0, $r->json('sembrado.asignaturas_sembradas'));
        $this->assertGreaterThan(0, $r->json('sembrado.saltadas_porque_ya_tenia'),
            'La segunda llamada tiene que decir que las saltó, no callarse.');
    }

    /**
     * **Lo que el docente le montó a mano no se toca.** Si ya tenía estructura propia en
     * una asignatura, esa asignatura se salta entera: ni se añade ni se reemplaza.
     */
    public function test_marcar_no_toca_la_estructura_propia_que_ya_tenia(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $asignatura = DB::selectOne(
            'SELECT a.id FROM asignaturas a
               INNER JOIN unidades u ON u.asignatura_id = a.id AND u.periodo_id = ?
                                    AND u.alumno_id IS NULL AND u.deleted_at IS NULL
              WHERE a.grupo_id = ? AND a.deleted_at IS NULL
              GROUP BY a.id LIMIT 1',
            [$periodo, $e['grupo']]
        );

        $this->assertNotNull($asignatura);

        DB::table('unidades')->insert([
            'definicion' => 'La que montó el docente',
            'porcentaje' => 100,
            'periodo_id' => $periodo,
            'asignatura_id' => $asignatura->id,
            'alumno_id' => $e['alumno'],
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true]);

        $r->assertStatus(200);

        $enEsa = array_map(static fn ($u) => $u->definicion, DB::select(
            'SELECT definicion FROM unidades
              WHERE alumno_id = ? AND asignatura_id = ? AND periodo_id = ? AND deleted_at IS NULL',
            [$e['alumno'], $asignatura->id, $periodo]
        ));

        $this->assertSame(['La que montó el docente'], $enEsa,
            'El sembrado de marcar se metió en una asignatura que el alumno ya tenía montada.');

        $this->assertGreaterThan(0, $r->json('sembrado.saltadas_porque_ya_tenia'));
    }

    /**
     * **Los ocho números vienen siempre, también en cero y también al desmarcar.**
     *
     * Es la mitad del contrato que el front pidió por escrito: *«un 0 sembradas tiene que
     * poder distinguirse de no revisó nada»*. Un bloque que omitiera los campos en cero
     * haría esas dos cosas indistinguibles para quien las pinta.
     *
     * Y al desmarcar el bloque **viene igual**, con `asignaturas_revisadas` en cero —ahí
     * no se revisa ninguna— y `casillas_nuevas` como lo único que puede subir: son las
     * del curso, que es lo que ese camino ya sembraba desde el 31 ago y **nunca contó**.
     */
    public function test_la_respuesta_trae_los_ocho_numeros_tambien_al_desmarcar(): void
    {
        $e = $this->escenario();
        $token = $this->tokenDelPersonalDe($e['year']);

        $campos = [
            'asignaturas_revisadas', 'asignaturas_sembradas', 'saltadas_porque_ya_tenia',
            'saltadas_sin_rejilla_del_grupo', 'unidades', 'subunidades', 'notas_traidas', 'casillas_nuevas',
        ];

        foreach ([true, false] as $aplica) {
            $r = $this->withToken($token)->putJson(self::RUTA,
                ['alumno_id' => $e['alumno'], 'periodo_id' => $e['periodos'][2], 'aplica' => $aplica]);

            $r->assertStatus(200);

            // Los tres de siempre siguen ahí, con su nombre y su tipo: el front
            // desplegado lee éstos y no puede enterarse de que hay un cuarto.
            $this->assertSame($e['alumno'], $r->json('alumno_id'));
            $this->assertSame($e['periodos'][2], $r->json('periodo_id'));
            $this->assertSame($aplica, $r->json('aplica'));

            foreach ($campos as $campo) {
                $this->assertIsInt($r->json('sembrado.'.$campo),
                    "Falta el número `{$campo}` con aplica=".var_export($aplica, true)
                    .': un campo que no viene cuando vale cero no se distingue de «no se revisó nada».');
            }

            if (! $aplica) {
                $this->assertSame(0, $r->json('sembrado.asignaturas_revisadas'),
                    'Desmarcar no revisa ninguna asignatura: ese cero es información.');
            }
        }
    }

    /*
     * **Aquí vivía `test_marcar_no_siembra_desempenos`**, y se fue el 17 sep 2026 con la
     * tabla `desempenos` entera (modelo plano,
     * [39](../../docs/migracion/39-el-modelo-plano-por-competencias.md)).
     *
     * Lo que fijaba **sigue siendo cierto y ya no puede romperse**: la última línea de D18
     * pedía que marcar sembrara los desempeños del grupo a nombre del alumno, y su premisa
     * era falsa porque `unidades` y `desempenos` leían `alumno_id` con semánticas opuestas
     * —una EXCLUYE, la otra SUMA—. Con D31 **no hay copia por asignatura donde sembrar
     * nada**: el plan de área es una sola tabla por año, sin `alumno_id`, y marcar un
     * boletín independiente no la toca ni podría. El caso se quita porque la tabla que
     * miraba no existe, no porque la regla se haya relajado.
     */

    /**
     * **El sembrado de marcar queda auditado, también cuando no siembra nada.**
     *
     * Es la razón que `putSembrar()` ya tiene escrita: *«alguien lo apretó y no pasó
     * nada»* es exactamente el suceso que alguien va a investigar dentro de un año. Se
     * mira **la fila que queda** y no el 200: `Auditoria::guardar()` se traga cualquier
     * excepción a propósito, así que un escritor roto contesta igual de contento.
     */
    public function test_marcar_deja_una_linea_de_auditoria_con_los_numeros(): void
    {
        $e = $this->escenario();
        $periodo = $e['periodos'][1];

        $antes = (int) DB::selectOne("SELECT COUNT(*) c FROM auditoria WHERE entidad = 'unidad'")->c;

        $this->withToken($this->tokenDelPersonalDe($e['year']))
            ->putJson(self::RUTA, ['alumno_id' => $e['alumno'], 'periodo_id' => $periodo, 'aplica' => true])
            ->assertStatus(200);

        $linea = DB::selectOne("SELECT valor_nuevo, resumen FROM auditoria WHERE entidad = 'unidad' ORDER BY id DESC LIMIT 1");

        $this->assertSame($antes + 1,
            (int) DB::selectOne("SELECT COUNT(*) c FROM auditoria WHERE entidad = 'unidad'")->c,
            'Marcar y sembrar ~120 filas no dejó ni una línea de auditoría.');

        $this->assertNotNull($linea);
        $this->assertStringContainsString('asignaturas_sembradas', (string) $linea->valor_nuevo,
            'La línea no lleva el recuento dentro: sin él sólo dice que alguien pulsó.');
    }
}
