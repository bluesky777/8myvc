<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Quién puede cambiarle a quién el nombre con el que entra al sistema.
 *
 * `PUT perfiles/guardar-username/{id}` no comprobaba nada. La ruta lleva
 * `persona.propia:user_id`, pero ese guard solo ata a Alumno y Acudiente
 * —`ExigirPersonaPropia:80` deja pasar a todos los demás—, así que **cualquiera
 * de los 51 profesores le cambiaba el nombre de usuario a cualquier cuenta,
 * incluida la de un superusuario**. Con `users.username` UNIQUE, eso deja a
 * alguien fuera del sistema sin saber su contraseña: basta quitarle el nombre.
 *
 * Lo encontró la sesión de `myvc_flutter` el 24 ago 2026 leyendo la ruta que su
 * pantalla nueva iba a consumir. Es la §29 —`putResetPassword`— sin hacer en el
 * hermano, y este fichero fija que las dos digan ya lo mismo.
 *
 * **Todos los casos se comprueban en negativo**: no basta con que la respuesta
 * sea 403, hay que ver que el username NO cambió. Un guard que aborta después de
 * escribir responde 403 igual.
 */
class GuardarUsernameTest extends CasoDeContrato
{
    /** Un profesor cualquiera con sesión: el atacante del caso. */
    private function profesor(): object
    {
        $u = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.is_superuser = 0
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún profesor con periodo.');

        return $u;
    }

    private function superusuario(): object
    {
        $u = DB::selectOne('SELECT u.* FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.tipo = "Usuario" AND u.is_superuser = 1 AND u.is_active = 1
              AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario con periodo.');

        return $u;
    }

    private function usernameDe(int $userId): string
    {
        return (string) DB::selectOne('SELECT username FROM users WHERE id = ?', [$userId])->username;
    }

    /**
     * El caso que se reportó: un profesor renombra al superusuario.
     *
     * Es el que convierte esto en escalada y no en molestia. El profesor no toma
     * la cuenta del superusuario: le quita el nombre con el que entra.
     */
    public function test_un_profesor_no_renombra_al_superusuario(): void
    {
        $profesor = $this->profesor();
        $victima = $this->superusuario();
        $antes = $this->usernameDe((int) $victima->id);

        $this->withToken($this->tokenDe($profesor->username))
            ->putJson('/api/perfiles/guardar-username/'.$victima->id, ['username' => 'tomada-9876'])
            ->assertStatus(403);

        $this->assertSame($antes, $this->usernameDe((int) $victima->id),
            'El superusuario fue renombrado de todas formas.');
    }

    /**
     * Y tampoco a otro profesor, que es la mitad que no es escalada pero sí es
     * dejar a un compañero fuera de su cuenta.
     */
    public function test_un_profesor_no_renombra_a_otro_profesor(): void
    {
        $profesor = $this->profesor();

        $otro = DB::selectOne('SELECT u.* FROM users u
            WHERE u.tipo = "Profesor" AND u.id != ? AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1', [$profesor->id]);

        $this->assertNotNull($otro, 'El seed necesita dos profesores para este caso.');

        $antes = $this->usernameDe((int) $otro->id);

        $this->withToken($this->tokenDe($profesor->username))
            ->putJson('/api/perfiles/guardar-username/'.$otro->id, ['username' => 'tomada-9877'])
            ->assertStatus(403);

        $this->assertSame($antes, $this->usernameDe((int) $otro->id));
    }

    /**
     * Lo que el arreglo NO quita: el superusuario sigue pudiendo con cualquiera.
     *
     * Sin esto el test de arriba pasaría también con el método roto de otra
     * forma —por ejemplo denegando a todo el mundo—, y el arreglo habría
     * cambiado un agujero por una pantalla que no funciona.
     */
    public function test_el_superusuario_si_renombra_a_cualquiera(): void
    {
        $jefe = $this->superusuario();
        $victima = $this->profesor();

        $nombre = 'renombrado-'.random_int(100000, 999999);

        $this->withToken($this->tokenDe($jefe->username))
            ->putJson('/api/perfiles/guardar-username/'.$victima->id, ['username' => $nombre])
            ->assertStatus(200);

        $this->assertSame($nombre, $this->usernameDe((int) $victima->id),
            'El superusuario no pudo renombrar, que es lo que sí debe poder.');
    }

    /**
     * El criterio del hermano, aplicado aquí: un docente con la bandera puede,
     * pero solo sobre un alumno.
     *
     * Es exactamente lo que hace `putResetPassword` desde la §29. Se comprueban
     * las dos mitades en la misma corrida para que el test no pueda pasar por la
     * razón equivocada.
     */
    public function test_un_docente_con_la_bandera_solo_renombra_alumnos(): void
    {
        $profesor = $this->profesor();

        // La bandera vive en `years`, no en `profesores`: es configuración del
        // colegio para el año. Y el orden importa —copiado de
        // `ResetearContrasenaTest`, que es el test del hermano—: se pide un token
        // primero porque `Services\Login` puede MOVER al profesor de periodo al
        // entrar, y la bandera que cuenta es la del año donde queda, no la del
        // año donde estaba. Encenderla antes de ese primer login la enciende en
        // el año equivocado y el test falla por el montaje.
        $this->tokenDe($profesor->username);

        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        DB::table('years')->where('id', $year->year_id)->update(['profes_can_edit_alumnos' => 1]);

        $token = $this->tokenDe($profesor->username);

        $alumno = $this->usuarioDeTipo('Alumno');

        $nombre = 'alumno-'.random_int(100000, 999999);

        $this->withToken($token)
            ->putJson('/api/perfiles/guardar-username/'.$alumno->id, ['username' => $nombre])
            ->assertStatus(200);

        $this->assertSame($nombre, $this->usernameDe((int) $alumno->id));

        // Y la otra mitad: la bandera se llama `can_edit_alumnos`, así que no
        // alcanza al superusuario. Es el fallo exacto que la §29 cerró en
        // `reset-password`, donde la bandera sí dejaba tomar cualquier cuenta.
        $jefe = $this->superusuario();
        $antes = $this->usernameDe((int) $jefe->id);

        $this->withToken($token)
            ->putJson('/api/perfiles/guardar-username/'.$jefe->id, ['username' => 'tomada-9878'])
            ->assertStatus(403);

        $this->assertSame($antes, $this->usernameDe((int) $jefe->id),
            'La bandera de editar alumnos alcanzó al superusuario.');
    }

    /**
     * Lo que se conserva a propósito: el caso propio.
     *
     * El guard ya dejaba hoy que un alumno cambiara SU nombre de usuario.
     * Quitarlo sería un cambio de comportamiento escondido dentro de un arreglo
     * de seguridad, así que el arreglo lo respeta y este test lo fija.
     */
    public function test_un_alumno_sigue_cambiando_el_suyo(): void
    {
        // `usuarioDeTipo` y no una consulta a mano: un alumno cuyo año no tiene
        // periodos da 400 `user_inactivo_por_falta_periodos` antes de llegar al
        // método, y el test fallaría por el seed y no por el guard.
        $alumno = $this->usuarioDeTipo('Alumno');

        $nombre = 'yo-'.random_int(100000, 999999);

        $this->withToken($this->tokenDe($alumno->username))
            ->putJson('/api/perfiles/guardar-username/'.$alumno->id, ['username' => $nombre])
            ->assertStatus(200);

        $this->assertSame($nombre, $this->usernameDe((int) $alumno->id));
    }

    /**
     * Un nombre ocupado es 422 y no un 500 de MySQL.
     *
     * `users.username` es UNIQUE y aquí no lo miraba nadie: pedir uno ocupado
     * reventaba con SQLSTATE 23000. Código nuevo, código correcto.
     */
    public function test_un_username_ocupado_es_422_y_no_revienta(): void
    {
        $jefe = $this->superusuario();
        $victima = $this->profesor();

        $ocupado = $this->usernameDe((int) $jefe->id);
        $antes = $this->usernameDe((int) $victima->id);

        $this->withToken($this->tokenDe($jefe->username))
            ->putJson('/api/perfiles/guardar-username/'.$victima->id, ['username' => $ocupado])
            ->assertStatus(422)
            // La FORMA, no solo el código: el front lee `message` —la de Laravel— y
            // una prueba suya llegó a esperar `msg`. Se fija aquí para que la
            // pregunta no haya que volver a hacerla. Lo pidió `myvc-front-10`.
            ->assertJson(['message' => 'Ese nombre de usuario ya está en uso.']);

        $this->assertSame($antes, $this->usernameDe((int) $victima->id));
    }

    /**
     * Y el vacío sigue siendo 400, que es lo único que el método comprobaba y no
     * hay razón para moverlo: el front ya lee esa respuesta.
     */
    public function test_el_vacio_sigue_siendo_400(): void
    {
        $jefe = $this->superusuario();
        $victima = $this->profesor();
        $antes = $this->usernameDe((int) $victima->id);

        $this->withToken($this->tokenDe($jefe->username))
            ->putJson('/api/perfiles/guardar-username/'.$victima->id, ['username' => '   '])
            ->assertStatus(400);

        $this->assertSame($antes, $this->usernameDe((int) $victima->id));
    }
}
