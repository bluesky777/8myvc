<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Quién puede poner la contraseña de quién, y qué contraseña vale.
 *
 * `perfiles/reset-password/{id}` es la única ruta que deja a una persona poner la
 * contraseña de otra sin conocer la anterior. Salió al mirar `PerfilesController`,
 * que era el segundo hueco de la cobertura del 20 de agosto —12 de 22—, y lo que
 * tenía dentro es lo más caro de la serie: un docente se hacía con la cuenta del
 * superusuario en una petición.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §29.
 */
class ResetearContrasenaTest extends CasoDeContrato
{
    /** Un profesor del seed con la bandera del año encendida, y su token. */
    private function profesorQuePuedeEditarAlumnos(): array
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        // La bandera se lee del año del periodo en el que queda el profesor
        // DESPUÉS de entrar, que es el que reescribe `Services\Login`.
        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        DB::table('years')->where('id', $year->year_id)->update(['profes_can_edit_alumnos' => 1]);

        return [$profesor, $this->tokenDe($profesor->username)];
    }

    private function usuarioDe(string $tipo): object
    {
        $fila = DB::selectOne('SELECT id, tipo, password FROM users
            WHERE tipo = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$tipo]);

        $this->assertNotNull($fila, "El seed no tiene ningún usuario de tipo {$tipo}.");

        return $fila;
    }

    /**
     * Un docente no toca la cuenta del superusuario, y antes sí.
     *
     * La comprobación miraba la bandera `profes_can_edit_alumnos` de quien pide y
     * no miraba a quién se le cambia. Con la bandera encendida —que es una
     * configuración normal del colegio— un profesor ponía la contraseña del
     * superusuario **y la recibía de vuelta en el cuerpo de la respuesta**.
     */
    public function test_un_docente_no_resetea_al_superusuario(): void
    {
        [, $token] = $this->profesorQuePuedeEditarAlumnos();

        $superusuario = DB::selectOne('SELECT id, password FROM users
            WHERE is_superuser = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $this->assertNotNull($superusuario, 'El seed no tiene ningún superusuario.');

        $this->withToken($token)
            ->putJson('/api/perfiles/reset-password/'.$superusuario->id, ['password' => 'tomada-1234'])
            ->assertStatus(403);

        $this->assertSame($superusuario->password,
            DB::table('users')->where('id', $superusuario->id)->value('password'),
            'La contraseña del superusuario se movió.');
    }

    /** Ni la de un profesor, ni la de un acudiente: la bandera dice «alumnos». */
    public function test_un_docente_solo_resetea_alumnos(): void
    {
        [, $token] = $this->profesorQuePuedeEditarAlumnos();

        foreach (['Profesor', 'Acudiente', 'Usuario'] as $tipo) {
            $objetivo = $this->usuarioDe($tipo);

            $this->withToken($token)
                ->putJson('/api/perfiles/reset-password/'.$objetivo->id, ['password' => 'tomada-1234'])
                ->assertStatus(403);

            $this->assertSame($objetivo->password,
                DB::table('users')->where('id', $objetivo->id)->value('password'),
                "La contraseña de un {$tipo} se movió.");
        }
    }

    /** Y sí resetea la de un alumno, que es para lo que existe la bandera. */
    public function test_un_docente_si_resetea_a_un_alumno(): void
    {
        [, $token] = $this->profesorQuePuedeEditarAlumnos();

        $alumno = $this->usuarioDe('Alumno');

        $r = $this->withToken($token)
            ->putJson('/api/perfiles/reset-password/'.$alumno->id, ['password' => 'nueva-1234']);

        $r->assertStatus(200);
        $this->assertTrue(Hash::check('nueva-1234',
            (string) DB::table('users')->where('id', $alumno->id)->value('password')));
    }

    /** El superusuario sigue reseteando a cualquiera, que es lo que ya hacía. */
    public function test_el_superusuario_resetea_a_cualquiera(): void
    {
        $superusuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');
        $token = $this->tokenDe($superusuario->username);

        foreach (['Alumno', 'Profesor', 'Acudiente'] as $tipo) {
            $objetivo = $this->usuarioDe($tipo);

            $this->withToken($token)
                ->putJson('/api/perfiles/reset-password/'.$objetivo->id, ['password' => 'nueva-1234'])
                ->assertStatus(200);

            $this->assertTrue(Hash::check('nueva-1234',
                (string) DB::table('users')->where('id', $objetivo->id)->value('password')),
                "No se pudo resetear la contraseña de un {$tipo}.");
        }
    }

    /**
     * La contraseña ya no viaja de vuelta en el cuerpo.
     *
     * Respondía `'Password cambiado -> loquefuera'`. El front enseña un aviso fijo
     * y no lee la respuesta; una contraseña en un cuerpo acaba en los registros de
     * quien esté en medio.
     */
    public function test_la_respuesta_no_lleva_la_contrasena(): void
    {
        $superusuario = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $alumno = $this->usuarioDe('Alumno');

        $r = $this->withToken($this->tokenDe($superusuario->username))
            ->putJson('/api/perfiles/reset-password/'.$alumno->id, ['password' => 'secreta-9876']);

        $r->assertStatus(200);
        $this->assertStringNotContainsString('secreta-9876', $r->getContent());
    }

    /**
     * Una contraseña vacía no es una contraseña, en las dos rutas que la escriben.
     *
     * `Hash::make('')` no falla: devuelve el hash de la cadena vacía, y
     * `login/credentials` con la contraseña vacía responde 200. Es el mismo fallo
     * de las masivas de la §26, y por eso la regla vive ya en `Support\ClaveNueva`
     * y no copiada en cada sitio.
     */
    public function test_la_contrasena_vacia_no_se_escribe(): void
    {
        $superusuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');
        $token = $this->tokenDe($superusuario->username);

        $alumno = $this->usuarioDe('Alumno');

        foreach ([[], ['password' => '']] as $cuerpo) {
            $this->withToken($token)
                ->putJson('/api/perfiles/reset-password/'.$alumno->id, $cuerpo)
                ->assertStatus(422);
        }

        $this->assertSame($alumno->password,
            DB::table('users')->where('id', $alumno->id)->value('password'));

        // Y la otra: cambiarse la propia, que exige la antigua.
        $propia = DB::table('users')->where('id', $superusuario->id)->value('password');

        foreach ([['oldpassword' => self::CLAVE], ['oldpassword' => self::CLAVE, 'password' => '']] as $cuerpo) {
            $this->withToken($token)
                ->putJson('/api/perfiles/cambiarpassword/'.$superusuario->id, $cuerpo)
                ->assertStatus(422);
        }

        $this->assertSame($propia, DB::table('users')->where('id', $superusuario->id)->value('password'));
    }

    /**
     * Crear las cuentas de todo el colegio es de administrativos.
     *
     * Recorre alumnos, profesores y acudientes y le crea cuenta al que no la
     * tenga. El botón vive en la pantalla de usuarios, que el menú del front
     * enseña solo a `admin`; el backend la dejaba a los 51 profesores.
     */
    public function test_crear_todas_las_cuentas_es_de_administrativos(): void
    {
        [, $token] = $this->profesorQuePuedeEditarAlumnos();

        $antes = DB::table('users')->count();

        $this->withToken($token)->putJson('/api/perfiles/creartodoslosusuarios', [])
            ->assertStatus(403);

        $this->assertSame($antes, DB::table('users')->count(),
            'Se crearon cuentas en una llamada que debía cortarse.');
    }

    /** Y una familia no llega a ninguna de las dos. */
    public function test_una_familia_no_resetea_contrasenas(): void
    {
        $alumno = $this->usuarioDe('Alumno');

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)
                ->putJson('/api/perfiles/reset-password/'.$alumno->id, ['password' => 'x'])
                ->assertStatus(400);

            $this->withToken($token)->putJson('/api/perfiles/creartodoslosusuarios', [])
                ->assertStatus(403);
        }

        $this->assertSame($alumno->password,
            DB::table('users')->where('id', $alumno->id)->value('password'));
    }
}
