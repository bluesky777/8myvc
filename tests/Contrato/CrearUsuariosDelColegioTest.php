<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El nombre de usuario que el colegio le fabrica a quien no tiene cuenta.
 *
 * `PUT api/perfiles/creartodoslosusuarios` recorre alumnos, profesores y
 * acudientes y le crea la cuenta al que no la tenga. Es **el mecanismo que
 * existe para que todo el mundo tenga una**, y fabricaba dos clases de cuenta
 * que su dueño no puede usar:
 *
 *   - `FILTER_SANITIZE_EMAIL` sobre un nombre castellano **borra** las tildes en
 *     vez de transliterarlas. `José Andrés` daba `JosAndrs` y `Ñoño` daba `oo`.
 *     El sanitizador es para correos; el nombre no lo es.
 *   - Con `nombres` vacío o todo espacios, el username salía **vacío**. En la
 *     base de desarrollo hay una cuenta así desde 2019 —usuario 842, un
 *     acudiente activo— y `acudientes` tiene dos filas con `nombres` en blanco,
 *     que es de donde salen.
 *
 * Lo segundo no es una puerta abierta: esa cuenta tiene su hash y la clave vacía
 * no entra. Es una cuenta inservible, y conviene decirlo así y no de más.
 *
 * Es la tercera cara de lo mismo que la §9: el mecanismo que reparte cuentas y
 * correos produce, en castellano, artefactos rotos. Ver
 * docs/migracion/12-larastan-nivel-7.md §12.
 */
class CrearUsuariosDelColegioTest extends CasoDeContrato
{
    private function superusuario(): string
    {
        $fila = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún superusuario activo.');

        return $this->tokenDe($fila->username);
    }

    /** Deja a un acudiente sin cuenta y con el nombre que se le pase. */
    private function acudienteSinCuentaLlamado(?string $nombres): int
    {
        $id = (int) DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL
            ORDER BY id LIMIT 1')->id;

        DB::update('UPDATE acudientes SET nombres = ?, user_id = NULL WHERE id = ?', [$nombres, $id]);

        return $id;
    }

    public function test_las_tildes_se_transliteran_en_vez_de_borrarse(): void
    {
        $id = $this->acudienteSinCuentaLlamado('José Andrés');

        $this->withToken($this->superusuario())
            ->putJson('/api/perfiles/creartodoslosusuarios', [])
            ->assertStatus(200);

        $username = DB::selectOne('SELECT u.username FROM acudientes a
            INNER JOIN users u ON u.id = a.user_id WHERE a.id = ?', [$id])?->username;

        $this->assertNotNull($username, 'Al acudiente no se le creó cuenta.');
        $this->assertStringStartsWith('JoseAndres', $username,
            "El nombre de usuario salió «{$username}»: las tildes se están borrando en vez de transliterarse.");
    }

    public function test_un_nombre_en_blanco_no_crea_una_cuenta_con_username_vacio(): void
    {
        $vaciosAntes = (int) DB::selectOne('SELECT COUNT(*) n FROM users
            WHERE deleted_at IS NULL AND TRIM(COALESCE(username, "")) = ""')->n;

        $id = $this->acudienteSinCuentaLlamado('   ');

        $this->withToken($this->superusuario())
            ->putJson('/api/perfiles/creartodoslosusuarios', [])
            ->assertStatus(200);

        $this->assertSame($vaciosAntes, (int) DB::selectOne('SELECT COUNT(*) n FROM users
            WHERE deleted_at IS NULL AND TRIM(COALESCE(username, "")) = ""')->n,
            'Se creó una cuenta con el nombre de usuario vacío.');

        $username = DB::selectOne('SELECT u.username FROM acudientes a
            INNER JOIN users u ON u.id = a.user_id WHERE a.id = ?', [$id])?->username;

        $this->assertNotNull($username, 'Al acudiente no se le creó cuenta.');
        $this->assertSame('acudiente'.$id, $username,
            'Sin nombre, el username debe caer a {tipo}{id} y no a la cadena vacía.');
    }

    /** Dos personas con el mismo nombre siguen sin chocar. */
    public function test_el_segundo_con_el_mismo_nombre_lleva_sufijo(): void
    {
        $primero = $this->acudienteSinCuentaLlamado('José Andrés');

        $segundo = (int) DB::selectOne('SELECT id FROM acudientes WHERE deleted_at IS NULL
            AND id != ? ORDER BY id LIMIT 1', [$primero])->id;
        DB::update('UPDATE acudientes SET nombres = ?, user_id = NULL WHERE id = ?',
            ['José Andrés', $segundo]);

        $this->withToken($this->superusuario())
            ->putJson('/api/perfiles/creartodoslosusuarios', [])
            ->assertStatus(200);

        $nombres = array_map(fn ($f) => $f->username, DB::select('SELECT u.username FROM acudientes a
            INNER JOIN users u ON u.id = a.user_id WHERE a.id IN (?, ?)', [$primero, $segundo]));

        $this->assertCount(2, $nombres, 'No se les creó cuenta a los dos.');
        $this->assertSame(2, count(array_unique($nombres)),
            'Los dos acudientes con el mismo nombre recibieron el mismo username: '.implode(', ', $nombres));
    }
}
