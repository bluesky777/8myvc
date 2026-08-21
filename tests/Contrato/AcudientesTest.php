<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Quién crea, edita y borra acudientes.
 *
 * Cuatro de las catorce rutas de `AcudientesController` no llevan
 * `auth.personal`: se defienden con una condición escrita a mano dentro del
 * método. Y esa condición pregunta por `$this->user->tipo == 'Secretario'`, un
 * valor que `users.tipo` **no puede tomar** —solo Usuario, Profesor, Alumno y
 * Acudiente, que son las cuatro ramas del `switch` del contexto—.
 *
 * Estos tests fijan lo que las condiciones hacen de verdad, no lo que dicen. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §30.2.
 */
class AcudientesTest extends CasoDeContrato
{
    private function tokenDeUnAdministrativoSinSuperusuario(): ?string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 0 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        return $fila === null ? null : $this->tokenDe($fila->username);
    }

    private function tokenDelSuperusuario(): string
    {
        $fila = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($fila->username);
    }

    /**
     * Lo que `postCrear` necesita de verdad, que no es solo el acudiente.
     *
     * El método crea **tres filas en una**: el acudiente, su parentesco con un
     * alumno y su cuenta de usuario. Sin `alumno_id` y sin
     * `parentesco['parentesco']` revienta dentro del `try` y sale por el
     * `abort(422, 'Datos incorrectos')`, que es el mismo 422 que cualquier otro
     * fallo del bloque. No hay acudiente sin alumno por esta puerta.
     */
    private function cuerpoDeAcudiente(): array
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        return [
            'nombres' => 'Acudiente', 'apellidos' => 'De Prueba', 'sexo' => 'F',
            'documento' => (string) random_int(800000000, 899999999),
            'celular' => '3000000000',
            'alumno_id' => $alumno->id,
            'parentesco' => ['parentesco' => 'Madre'],
        ];
    }

    /** El profesor y el superusuario crean acudientes, que es lo que hace la ruta. */
    public function test_el_personal_docente_y_el_superusuario_crean_acudientes(): void
    {
        foreach (['Profesor' => null, 'super' => null] as $quien => $_) {
            $token = $quien === 'super'
                ? $this->tokenDelSuperusuario()
                : $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

            $antes = DB::table('acudientes')->count();

            $this->withToken($token)->postJson('/api/acudientes/crear', $this->cuerpoDeAcudiente())
                ->assertStatus(200);

            $this->assertSame($antes + 1, DB::table('acudientes')->count(),
                "El acudiente creado por {$quien} no llegó a la tabla.");
        }
    }

    /**
     * Y crea tres filas, no una: acudiente, parentesco y cuenta.
     *
     * Lo que hace útil mirarlo es la cuenta. Nace con `periodo_id = 1` escrito a
     * mano —el periodo 1 de la base es de 2018— y con la contraseña `123456` por
     * defecto. Ninguna de las dos se ve desde la pantalla que la crea: el periodo
     * lo corrige `Services\Login` la primera vez que el acudiente entra, y la
     * contraseña es la que el colegio reparte. Queda medido para que el día que
     * se toque se sepa qué había.
     */
    public function test_crear_un_acudiente_crea_tambien_su_parentesco_y_su_cuenta(): void
    {
        $token = $this->tokenDelSuperusuario();
        $cuerpo = $this->cuerpoDeAcudiente();

        $this->withToken($token)->postJson('/api/acudientes/crear', $cuerpo)->assertStatus(200);

        $acudiente = DB::selectOne('SELECT * FROM acudientes WHERE documento = ?', [$cuerpo['documento']]);
        $this->assertNotNull($acudiente);

        $this->assertSame(1, DB::table('parentescos')
            ->where('acudiente_id', $acudiente->id)->where('alumno_id', $cuerpo['alumno_id'])
            ->whereNull('deleted_at')->count());

        $usuario = DB::table('users')->where('id', $acudiente->user_id)->first();
        $this->assertNotNull($usuario, 'El acudiente se creó sin cuenta.');
        $this->assertSame('Acudiente', $usuario->tipo);
        $this->assertSame($cuerpo['documento'], $usuario->username,
            'El nombre de usuario sale del documento cuando lo hay.');
        $this->assertSame(1, (int) $usuario->periodo_id,
            'periodo_id va escrito a mano a 1; si esto cambia, cambió a propósito.');
        $this->assertTrue(Hash::check('123456', (string) $usuario->password),
            'La contraseña por defecto de un acudiente nuevo es 123456.');
    }

    /**
     * Y un administrativo que no sea superusuario, **no** — que es lo contrario
     * de lo que la línea pretendía decir.
     *
     * La condición es `is_superuser || tipo == 'Profesor' || tipo == 'Secretario'`,
     * y la tercera rama no puede cumplirse. Así que el secretario del colegio,
     * que es de quien es esta pantalla, recibe un 403 mientras cualquiera de los
     * 51 profesores sí crea acudientes.
     *
     * Se fija así a propósito: el día que se decida quién es el «Secretario»
     * (09 §5), este test falla y señala el sitio.
     */
    public function test_un_administrativo_sin_superusuario_no_crea_acudientes(): void
    {
        $token = $this->tokenDeUnAdministrativoSinSuperusuario();

        if ($token === null) {
            $this->markTestSkipped('El seed no tiene ningún Usuario sin is_superuser con periodo.');
        }

        $antes = DB::table('acudientes')->count();

        $this->withToken($token)->postJson('/api/acudientes/crear', $this->cuerpoDeAcudiente())
            ->assertStatus(403);

        $this->assertSame($antes, DB::table('acudientes')->count());
    }

    /** Una familia no crea acudientes ni los borra. */
    public function test_una_familia_no_crea_ni_borra_acudientes(): void
    {
        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->postJson('/api/acudientes/crear', $this->cuerpoDeAcudiente())
                ->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/acudientes/destroy/'.$acudiente->id)
                ->assertStatus(403);
            $this->withToken($token)->putJson('/api/acudientes/guardar-valor',
                ['acudiente_id' => $acudiente->id, 'propiedad' => 'celular', 'valor' => '3999999999'])
                ->assertStatus(403);
        }

        $this->assertNull(DB::table('acudientes')->where('id', $acudiente->id)->value('deleted_at'));
    }

    /**
     * Borrar un acudiente es más estrecho que crearlo: solo superusuario.
     *
     * Su condición no lleva la rama de `Profesor`, así que aquí la de
     * `'Secretario'` es lo único que separaba «superusuario» de «superusuario o
     * secretaría» — y como no puede cumplirse, hoy es superusuario a secas.
     */
    public function test_borrar_un_acudiente_es_solo_de_superusuario(): void
    {
        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->deleteJson('/api/acudientes/destroy/'.$acudiente->id)->assertStatus(403);

        $this->assertNull(DB::table('acudientes')->where('id', $acudiente->id)->value('deleted_at'));

        $this->withToken($this->tokenDelSuperusuario())
            ->deleteJson('/api/acudientes/destroy/'.$acudiente->id)->assertStatus(200);

        $this->assertNotNull(DB::table('acudientes')->where('id', $acudiente->id)->value('deleted_at'));
    }

    /**
     * Y borrar el acudiente se lleva sus parentescos, que es la mitad que no se ve.
     *
     * Sin eso, el alumno se queda apuntando a un acudiente que ya no está y las
     * pantallas de familia lo enseñan vacío en vez de no enseñarlo.
     */
    public function test_borrar_un_acudiente_se_lleva_sus_parentescos(): void
    {
        $conParentesco = DB::selectOne('SELECT a.id FROM acudientes a
            INNER JOIN parentescos p ON p.acudiente_id = a.id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($conParentesco, 'El seed no tiene ningún acudiente con parentesco.');

        $antes = DB::table('parentescos')->where('acudiente_id', $conParentesco->id)
            ->whereNull('deleted_at')->count();
        $this->assertGreaterThan(0, $antes);

        $this->withToken($this->tokenDelSuperusuario())
            ->deleteJson('/api/acudientes/destroy/'.$conParentesco->id)->assertStatus(200);

        $this->assertSame(0, DB::table('parentescos')->where('acudiente_id', $conParentesco->id)
            ->whereNull('deleted_at')->count(),
            'Los parentescos del acudiente borrado siguen vivos.');
    }

    /** El parentesco se crea, se cambia y se quita, que es el trío de esa pantalla. */
    public function test_el_parentesco_se_pone_y_se_quita(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $acudiente = DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $r = $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
            'acudiente_id' => $acudiente->id, 'alumno_id' => $alumno->id,
            'parentesco' => 'Tío', 'observaciones' => 'de prueba',
        ]);

        $r->assertStatus(200);

        $fila = DB::selectOne('SELECT id, parentesco FROM parentescos
            WHERE acudiente_id = ? AND alumno_id = ? AND deleted_at IS NULL
            ORDER BY id DESC LIMIT 1', [$acudiente->id, $alumno->id]);

        $this->assertNotNull($fila, 'El parentesco no llegó a escribirse.');
        $this->assertSame('Tío', $fila->parentesco);

        $this->withToken($token)->putJson('/api/acudientes/seleccionar-parentesco', [
            'parentesco_acudiente_cambiar_id' => $fila->id,
            'acudiente_id' => $acudiente->id, 'alumno_id' => $alumno->id,
            'parentesco' => 'Abuelo', 'observaciones' => 'corregido',
        ])->assertStatus(200);

        $this->assertSame('Abuelo',
            DB::table('parentescos')->where('id', $fila->id)->value('parentesco'),
            'Mandando el id se debe corregir la fila, no crear otra.');

        $this->withToken($token)->putJson('/api/acudientes/quitar-parentesco-alumno',
            ['parentesco_id' => $fila->id])->assertStatus(200);

        $this->assertNotNull(DB::table('parentescos')->where('id', $fila->id)->value('deleted_at'));
    }
}
