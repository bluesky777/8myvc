<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rutas que reciben un identificador de fuera y lo usan sin comprobarlo.
 *
 * Lo que queda del recuento de `Modelo::find()` sin `OrFail` (05 §52) una vez
 * quitado el bucle de reordenar: cuatro sitios en los que el id viaja en el
 * cuerpo o en la URL, `find()` devuelve null y la línea siguiente lo usa como
 * objeto. **500 donde tocaba 404** — y un 500 con `APP_DEBUG` encendido devuelve
 * la traza, que es lo que lo separa de un código simplemente mal elegido.
 */
class IdentificadoresQueNoExistenTest extends CasoDeContrato
{
    private function token(): string
    {
        $super = DB::selectOne('SELECT username FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        return $this->tokenDe($super->username);
    }

    /** @return array<string, array{0:string,1:string,2:string}> ruta, tabla y clave del cuerpo */
    public static function rutasConIdDeFuera(): array
    {
        return [
            'actualizar ciudad' => ['/api/ciudades/actualizar-ciudad', 'ciudades', 'id'],
            'actualizar departamento' => ['/api/ciudades/actualizar-departamento', 'ciudades', 'id'],
        ];
    }

    #[DataProvider('rutasConIdDeFuera')]
    public function test_un_id_del_cuerpo_que_no_existe_es_404(string $ruta, string $tabla, string $clave): void
    {
        $maximo = (int) DB::table($tabla)->max('id');

        $this->withToken($this->token())
            ->putJson($ruta, [$clave => $maximo + 1000, 'ciudad' => 'X', 'departamento' => 'Y'])
            ->assertStatus(404);
    }

    /** Un usuario que no existe, al que se le intenta dar un rol. */
    public function test_dar_un_rol_a_un_usuario_que_no_existe_es_404(): void
    {
        $rol = DB::selectOne('SELECT id FROM roles ORDER BY id LIMIT 1');
        $maximo = (int) DB::table('users')->max('id');

        $this->withToken($this->token())
            ->putJson("/api/roles/addroletouser/{$rol->id}", ['user_id' => $maximo + 1000])
            ->assertStatus(404);
    }

    /** Y un rol que no existe, al que se le intenta quitar un usuario. */
    public function test_quitar_un_rol_que_no_existe_es_404(): void
    {
        $usuario = DB::selectOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $maximo = (int) DB::table('roles')->max('id');

        $this->withToken($this->token())
            ->putJson('/api/roles/removeroletouser/'.($maximo + 1000), ['user_id' => $usuario->id])
            ->assertStatus(404);
    }

    /** Y el camino bueno de ciudades sigue guardando. */
    public function test_una_ciudad_se_actualiza(): void
    {
        $ciudad = DB::selectOne('SELECT id FROM ciudades ORDER BY id LIMIT 1');

        $this->withToken($this->token())
            ->putJson('/api/ciudades/actualizar-ciudad', [
                'id' => $ciudad->id, 'ciudad' => 'Villa Nueva', 'departamento' => 'Antioquia',
            ])->assertStatus(200);

        $this->assertSame('Villa Nueva', DB::table('ciudades')->where('id', $ciudad->id)->value('ciudad'));
    }
}
