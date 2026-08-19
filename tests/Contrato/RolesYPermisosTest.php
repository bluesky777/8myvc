<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * La pantalla de roles y permisos, que respondía 500 en sus tres endpoints.
 *
 * `App\Models\Permission` extendía `Zizaco\Entrust\EntrustPermission`, y ese
 * paquete no está en el `composer.json` ni en el `composer.lock`. Cargar la
 * clase era un fatal, así que `GET permissions` y `PUT roles/addpermission`
 * caían siempre; `PUT roles/removepermission` caía por su cuenta, usando la
 * facade `Input`, que no existe desde Laravel 6.
 *
 * Los tres tienen cliente: son los botones de la pantalla de administración de
 * roles. Que llevaran años rotos encaja con el resto de esta lista — el error
 * sale en la consola del navegador, no en la pantalla.
 */
class RolesYPermisosTest extends CasoDeContrato
{
    private function tokenDeSuperusuario(): string
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->assertSame(1, (int) $usuario->is_superuser,
            'El test necesita un superusuario; el primer Usuario del seed no lo es.');

        return $this->tokenDe($usuario->username);
    }

    public function test_el_catalogo_de_permisos_se_puede_leer(): void
    {
        $token = $this->tokenDeSuperusuario();

        $r = $this->getJson('/api/permissions', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->assertSame(
            DB::table('permissions')->whereNull('deleted_at')->count(),
            count($r->json()),
        );
    }

    public function test_anadir_y_quitar_un_permiso_de_un_rol(): void
    {
        $token = $this->tokenDeSuperusuario();

        // Un par (rol, permiso) que no esté ya unido, para que el INSERT y el
        // DELETE se noten de verdad.
        $par = DB::select('SELECT r.id role_id, p.id permission_id
            FROM roles r CROSS JOIN permissions p
            WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM permission_role pr
                              WHERE pr.role_id = r.id AND pr.permission_id = p.id)
            LIMIT 1')[0];

        $cabeceras = ['Authorization' => 'Bearer '.$token];
        $cuerpo = ['permission_id' => $par->permission_id];

        $this->putJson("/api/roles/addpermission/{$par->role_id}", $cuerpo, $cabeceras)
            ->assertStatus(200)
            ->assertJsonPath('id', $par->permission_id);

        $this->assertSame(1, DB::table('permission_role')
            ->where('role_id', $par->role_id)
            ->where('permission_id', $par->permission_id)->count());

        $this->putJson("/api/roles/removepermission/{$par->role_id}", $cuerpo, $cabeceras)
            ->assertStatus(200);

        $this->assertSame(0, DB::table('permission_role')
            ->where('role_id', $par->role_id)
            ->where('permission_id', $par->permission_id)->count());
    }

    public function test_anadir_dos_veces_el_mismo_permiso_no_revienta(): void
    {
        $token = $this->tokenDeSuperusuario();

        $par = DB::select('SELECT role_id, permission_id FROM permission_role LIMIT 1')[0];

        $this->putJson("/api/roles/addpermission/{$par->role_id}",
            ['permission_id' => $par->permission_id], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);
    }

    public function test_un_permiso_que_no_existe_es_404(): void
    {
        $token = $this->tokenDeSuperusuario();

        $this->putJson('/api/roles/addpermission/1', ['permission_id' => 999999999],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404);
    }
}
