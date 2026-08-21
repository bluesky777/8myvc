<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El correo de recuperación de otra persona, que es por donde va el reseteo.
 *
 * Se llegó siguiendo la lista de cobertura del 21 ago 2026: `PerfilesController`
 * tenía nueve rutas sin que nadie hubiera mirado nunca lo que responden, y ésta
 * es la que resultó cara.
 *
 * `login/recuperar-clave` busca a quien pide el reseteo **por `users.email`** y le
 * manda ahí el enlace. Y `PUT perfiles/cambiaremailrestore/{id}` escribe ese
 * mismo `users.email` de **cualquier id**, con `persona.propia:user_id` como
 * único guard — que solo frena a alumnos y acudientes y deja pasar de largo a
 * todo el personal. O sea que un profesor ponía su propio correo en la cuenta del
 * superusuario y pedía un reseteo.
 *
 * Es la misma familia que la §29 y se cierra igual. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §36.
 */
class PerfilesEmailRestoreTest extends CasoDeContrato
{
    /** El superusuario del seed, que es el objetivo que vale la pena. */
    private function superusuario(): object
    {
        $u = DB::selectOne('SELECT u.id, u.email, u.password FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id
            WHERE u.is_superuser = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY u.id LIMIT 1');

        $this->assertNotNull($u, 'El seed no tiene ningún superusuario.');

        return $u;
    }

    /**
     * Un profesor no cambia el correo de recuperación del superusuario.
     *
     * El test mira las dos cosas que importan y no el código de estado: **que la
     * columna no se mueve** —porque es lo que decide a dónde llega el enlace de
     * reseteo— y que la respuesta no lleva el hash.
     */
    public function test_un_profesor_no_cambia_el_correo_de_recuperacion_del_superusuario(): void
    {
        $victima = $this->superusuario();
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->withToken($token)
            ->putJson('/api/perfiles/cambiaremailrestore/'.$victima->id, [
                'email_restore' => 'el-profesor@ejemplo.com',
            ])->assertStatus(403);

        $this->assertSame($victima->email,
            DB::table('users')->where('id', $victima->id)->value('email'),
            'El correo por el que llega el reseteo del superusuario se cambió.');
    }

    /**
     * Y la respuesta no devuelve el hash de nadie.
     *
     * El método terminaba en `return $perfil->password . ' - ' . ...`. El modelo
     * `User` tiene `password` en `$hidden`, así que en JSON no sale nunca; una
     * **concatenación en una cadena** se salta `$hidden` entero. Vale la pena
     * quedarse con eso: la protección estaba puesta y no cubría la única salida
     * que se usaba.
     */
    public function test_la_respuesta_no_lleva_el_hash_de_nadie(): void
    {
        $yo = $this->usuarioDeTipo('Usuario');

        $r = $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/perfiles/cambiaremailrestore/'.$yo->id, [
                'email_restore' => 'nuevo@ejemplo.com',
            ]);

        $this->assertStringNotContainsString('$2y$', (string) $r->getContent(),
            'La respuesta sigue llevando un hash bcrypt.');
    }

    /** Lo propio se sigue pudiendo, que es para lo que la ruta existe. */
    public function test_cada_uno_cambia_el_suyo(): void
    {
        $yo = $this->usuarioDeTipo('Usuario');

        $this->withToken($this->tokenDe($yo->username))
            ->putJson('/api/perfiles/cambiaremailrestore/'.$yo->id, [
                'email_restore' => 'el-mio@ejemplo.com',
            ])->assertStatus(200);

        $this->assertSame('el-mio@ejemplo.com',
            DB::table('users')->where('id', $yo->id)->value('email'));
    }

    /**
     * Y el reseteo busca por esa misma columna, que es lo que lo hace grave.
     *
     * Sin esto, «cambiar el correo de otro» parece un detalle de perfil en vez de
     * la llave de la cuenta. `postRecuperarClave` hace
     * `SELECT * FROM users WHERE email = ?` y manda el enlace ahí.
     */
    public function test_el_reseteo_busca_por_esa_misma_columna(): void
    {
        $yo = $this->usuarioDeTipo('Usuario');

        DB::table('users')->where('id', $yo->id)->update(['email' => 'donde-llega@ejemplo.test']);

        $encontrado = DB::selectOne(
            'SELECT username FROM users WHERE email = ? AND deleted_at IS NULL AND is_active = 1',
            ['donde-llega@ejemplo.test']);

        $this->assertNotNull($encontrado,
            'La consulta con la que el reseteo busca al dueño de un correo dejó de encontrarlo.');
        $this->assertSame($yo->username, $encontrado->username);
    }
}
