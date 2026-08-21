<?php

namespace Tests\Contrato;

use App\Support\Autoriza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Las cuatro rutas que cambian la cuenta de TODOS los alumnos o acudientes.
 *
 * `CambiarUsuariosController` era otro de los cinco controladores que la
 * cobertura del 20 de agosto dio con cero respuestas comprobadas, y es el que
 * más lejos llega de los cinco: dos de sus rutas reescriben el nombre de usuario
 * de todos los alumnos del colegio y las otras dos su contraseña.
 *
 * Ningún cliente las llama —comprobado en los tres—, así que quien las usa
 * escribe la petición a mano. Eso es justo lo que hacía peligroso que la clave
 * fuera opcional. Ver docs/migracion/05-codigo-muerto-y-roto.md §26.
 */
class OperacionesMasivasTest extends CasoDeContrato
{
    private function tokenDelSuperusuario(): string
    {
        $u = DB::selectOne('SELECT u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $this->tokenDe($u->username);
    }

    /**
     * Sin clave no se toca nada, y antes se tocaba todo.
     *
     * `Hash::make(null)` no falla: devuelve el hash de la cadena vacía. La
     * llamada respondía 200 y dejaba a los 1.280 alumnos del colegio con esa
     * contraseña — y `login/credentials` con la contraseña vacía responde 200
     * detrás de eso, comprobado aquí mismo.
     */
    public function test_sin_clave_no_se_cambia_ninguna_contrasena(): void
    {
        $token = $this->tokenDelSuperusuario();

        foreach (['alumnos' => 'Alumno', 'acudientes' => 'Acudiente'] as $ruta => $tipo) {
            $antes = DB::table('users')->where('tipo', $tipo)->orderBy('id')->pluck('password')->all();
            $this->assertNotEmpty($antes, "El seed no tiene usuarios de tipo {$tipo}.");

            foreach ([[], ['clave' => '']] as $cuerpo) {
                $this->withToken($token)
                    ->putJson("/api/cambiar-usuarios/poner-password-todos-{$ruta}", $cuerpo)
                    ->assertStatus(422);
            }

            $this->assertSame($antes,
                DB::table('users')->where('tipo', $tipo)->orderBy('id')->pluck('password')->all(),
                "Una llamada sin clave cambió las contraseñas de los {$ruta}.");
        }
    }

    /** Y con clave sigue cambiándolas, que es para lo que existe. */
    public function test_con_clave_las_cambia_todas(): void
    {
        $token = $this->tokenDelSuperusuario();

        $r = $this->withToken($token)->putJson(
            '/api/cambiar-usuarios/poner-password-todos-alumnos', ['clave' => 'nueva-9876']);

        $r->assertStatus(200);
        $this->assertSame('Contraseñas alumnos cambiadas', $r->getContent());

        $hash = DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->value('password');
        $this->assertTrue(Hash::check('nueva-9876', (string) $hash),
            'La contraseña nueva no quedó puesta.');
        $this->assertFalse(Hash::check('', (string) $hash));
    }

    /**
     * El nombre de usuario pasa a ser el documento, y solo de quien lo tenga.
     *
     * La consulta filtra `documento > 0 and is not null and != ''` y usa
     * `UPDATE IGNORE`, así que un documento repetido no se lleva por delante al
     * primero: lo salta. Se comprueba que quien no tiene documento conserva su
     * nombre de usuario, que es la mitad que un `IGNORE` puede esconder.
     */
    public function test_el_documento_pasa_a_ser_el_nombre_de_usuario(): void
    {
        $token = $this->tokenDelSuperusuario();

        $conDocumento = DB::selectOne('SELECT u.id, a.documento FROM users u
            INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
            WHERE u.tipo = "Alumno" AND u.deleted_at IS NULL
              AND a.documento > 0 AND a.documento IS NOT NULL AND a.documento <> ""
            GROUP BY a.documento HAVING COUNT(*) = 1 ORDER BY u.id LIMIT 1');

        $this->assertNotNull($conDocumento, 'El seed no tiene ningún alumno con documento único.');

        $r = $this->withToken($token)
            ->putJson('/api/cambiar-usuarios/poner-documento-como-username-alumnos', []);

        $r->assertStatus(200)->assertExactJson(['resultado' => 'Usernames cambiados.']);

        $this->assertSame((string) $conDocumento->documento,
            (string) DB::table('users')->where('id', $conDocumento->id)->value('username'));
    }

    /** @return list<string> Las cuatro, para no escribirlas cuatro veces. */
    private function lasCuatro(): array
    {
        return [
            'cambiar-usuarios/poner-documento-como-username-alumnos',
            'cambiar-usuarios/poner-documento-como-username-acudientes',
            'cambiar-usuarios/poner-password-todos-alumnos',
            'cambiar-usuarios/poner-password-todos-acudientes',
        ];
    }

    /** Un alumno no dispara ninguna de las cuatro: son de `auth.personal`. */
    public function test_una_familia_no_dispara_las_masivas(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $antes = DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('password')->all();

        foreach ($this->lasCuatro() as $ruta) {
            $this->withToken($token)->putJson('/api/'.$ruta, ['clave' => 'x'])->assertStatus(403);
        }

        $this->assertSame($antes,
            DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('password')->all());
    }

    /**
     * Y un profesor tampoco, que es lo que faltaba.
     *
     * Con `auth.personal` a secas las disparaba cualquiera de los 51 profesores
     * del colegio: reiniciar la contraseña de los 1.280 alumnos era una petición
     * a mano de distancia. Ahora piden el mismo criterio que la papelera de
     * grupos y profesores — `Autoriza::esAdministrativo` —, que es la misma clase
     * de operación.
     */
    public function test_un_profesor_no_dispara_las_masivas(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $antes = DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('password')->all();
        $usernames = DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('username')->all();

        foreach ($this->lasCuatro() as $ruta) {
            $this->withToken($token)->putJson('/api/'.$ruta, ['clave' => 'x'])->assertStatus(403);
        }

        $this->assertSame($antes,
            DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('password')->all());
        $this->assertSame($usernames,
            DB::table('users')->where('tipo', 'Alumno')->orderBy('id')->pluck('username')->all());
    }

    /**
     * El criterio se lee del propio `Autoriza`, no se copia aquí.
     *
     * En la base de desarrollo no existe el rol `Secretario`, así que hoy
     * `esAdministrativo` vale exactamente `is_superuser` y un test escrito con
     * «superusuario sí, profesor no» pasaría por la razón equivocada el día que
     * el colegio cree ese rol. Se comprueba la regla, no su valor de hoy.
     */
    public function test_el_criterio_es_el_de_autoriza(): void
    {
        $superusuario = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $profesor = $this->usuarioDeTipo('Profesor');

        $this->assertTrue(Autoriza::esAdministrativo(
            (object) ['is_superuser' => $superusuario->is_superuser, 'user_id' => $superusuario->id]));

        $this->assertFalse(Autoriza::esAdministrativo(
            (object) ['is_superuser' => $profesor->is_superuser, 'user_id' => $profesor->id]),
            'Si un profesor pasa el criterio, las cuatro masivas vuelven a estar abiertas a los 51.');
    }
}
