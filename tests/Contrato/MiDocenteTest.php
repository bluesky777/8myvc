<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT api/users/mi-docente`: qué docente mira una cuenta administrativa.
 *
 * La columna `users.profesor_id` llevaba años **leída y nunca escrita**: la
 * manda `ContextoDeUsuario` dentro de la sesión y `ChangeAskedController` la usa
 * para el horario del día, pero ningún `UPDATE users` del repositorio la tocaba
 * —los cuatro que hay son de contraseña, correo, username y `periodo_id`—. Las
 * dieciséis cuentas de tipo `Usuario` del colegio la tienen en `NULL`.
 *
 * Lo que estos tests fijan no es que la ruta responda: es que **la fila queda
 * escrita**, que es lo que se lee después, y que las dos comprobaciones que la
 * columna no tiene por esquema —no hay clave foránea— las hace el endpoint.
 *
 * Ruta nueva, pedida por Joseth el 28 ago 2026 para el selector de docente del
 * panel de `myvc_front`. Ver `UsersController::putMiDocente`.
 */
class MiDocenteTest extends CasoDeContrato
{
    /**
     * Una cuenta administrativa del seed, ya con su token.
     *
     * El token se pide ANTES de leer el año, y no al revés: `Services\Login`
     * reescribe `users.periodo_id` al periodo actual en cada inicio de sesión,
     * así que el año de antes de entrar no tiene por qué ser el que mira el
     * controlador. Es la misma trampa que documenta `ConcederSuperusuarioTest`.
     */
    private function administrador(): array
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($usuario->username);

        $year = DB::selectOne('SELECT p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$usuario->id]);

        return [$token, $usuario->id, $year->year_id];
    }

    /** Un profesor contratado en ese año, que es el único que el endpoint acepta. */
    private function contratadoEn(int $yearId): int
    {
        $fila = DB::selectOne('SELECT p.id FROM profesores p
            INNER JOIN contratos c ON c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
            WHERE p.deleted_at IS NULL ORDER BY p.id LIMIT 1', [$yearId]);

        $this->assertNotNull($fila,
            'El seed no tiene ningún profesor contratado en el año de la cuenta: el test no mediría nada.');

        return (int) $fila->id;
    }

    public function test_guarda_el_docente_en_la_fila_de_la_cuenta(): void
    {
        [$token, $userId, $yearId] = $this->administrador();
        $profesorId = $this->contratadoEn($yearId);

        $this->withToken($token)
            ->putJson('/api/users/mi-docente', ['profesor_id' => $profesorId])
            ->assertStatus(200)
            ->assertJsonPath('profesor_id', $profesorId);

        $this->assertSame($profesorId,
            (int) DB::table('users')->where('id', $userId)->value('profesor_id'),
            'La ruta contestó bien y la columna se quedó como estaba.');
    }

    /**
     * Y la sesión lo devuelve, que es la mitad por la que existe la ruta.
     *
     * El front no vuelve a preguntar por el docente elegido: lo lee de
     * `auth/me`, donde la columna viaja desde siempre. Si esto se rompiera, el
     * selector del panel saldría vacío después de guardar y nadie sabría por
     * qué —la escritura habría funcionado—.
     */
    public function test_la_sesion_devuelve_el_docente_recien_elegido(): void
    {
        [$token, , $yearId] = $this->administrador();
        $profesorId = $this->contratadoEn($yearId);

        $this->withToken($token)->putJson('/api/users/mi-docente', ['profesor_id' => $profesorId]);

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('profesor_id', $profesorId);
    }

    /** `null` es «ya no miro a nadie», y es una respuesta legítima, no un error. */
    public function test_se_puede_dejar_de_mirar_a_un_docente(): void
    {
        [$token, $userId, $yearId] = $this->administrador();

        $this->withToken($token)
            ->putJson('/api/users/mi-docente', ['profesor_id' => $this->contratadoEn($yearId)]);

        $this->withToken($token)
            ->putJson('/api/users/mi-docente', ['profesor_id' => null])
            ->assertStatus(200)
            ->assertJsonPath('profesor_id', null);

        $this->assertNull(DB::table('users')->where('id', $userId)->value('profesor_id'));
    }

    /**
     * Un profesor que no da clase este año no se puede elegir.
     *
     * `users.profesor_id` **no tiene clave foránea**, así que sin esta
     * comprobación la columna admite cualquier entero. Un id que no existe
     * dejaría a la cuenta apuntando a nadie —el mismo contrato huérfano de la
     * §78, con otro nombre— y el panel se quedaría en blanco sin decir por qué.
     */
    public function test_un_docente_sin_contrato_de_este_anio_no_se_guarda(): void
    {
        [$token, $userId] = $this->administrador();

        $inventado = ((int) DB::table('profesores')->max('id')) + 1000;

        $this->withToken($token)
            ->putJson('/api/users/mi-docente', ['profesor_id' => $inventado])
            ->assertStatus(422);

        $this->assertNull(DB::table('users')->where('id', $userId)->value('profesor_id'),
            'Se escribió un profesor que no existe.');
    }

    /**
     * Y un profesor no elige docente: su identidad sale de `profesores.id`.
     *
     * `auth.personal` deja pasar a todo el que no sea alumno ni acudiente, así
     * que la puerta de este endpoint no puede ser el middleware. Para una cuenta
     * de tipo `Profesor` esta columna no se mira en ningún sitio: lo que se
     * guardaría es un dato que nadie lee y que contradice al que sí se lee.
     */
    public function test_un_profesor_no_elige_docente(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $this->withToken($token)
            ->putJson('/api/users/mi-docente', ['profesor_id' => 1])
            ->assertStatus(403);

        $this->assertNull(DB::table('users')->where('id', $profesor->id)->value('profesor_id'));
    }
}
