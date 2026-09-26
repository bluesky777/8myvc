<?php

namespace Tests\Contrato;

use App\Models\Role;
use App\Services\ContextoDeUsuario;
use App\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * EL TIPO TRAE SU ROL (26 sep 2026, `Role::rolDelTipo`).
 *
 * Joseth: *«quisiera que el sistema reconociera el tipo y ya suponga que tiene ese rol, además de
 * los demás que se le hayan asignado a ese usuario»*. Un usuario de tipo Profesor, Alumno o
 * Acudiente tiene el rol del mismo nombre sin fila en `role_user`, y lo tiene en los tres sitios
 * que enseñan o deciden roles: `Role::getUserRoles` (Autoriza), el contexto de la sesión (el menú
 * del front) y `User::rolesConLosImplicitos` (la rejilla de usuarios).
 */
class RolDelTipoTest extends CasoDeContrato
{
    /** Un usuario del tipo pedido, sin ninguna fila en `role_user`. */
    private function sinRoles(string $tipo): int
    {
        $id = (int) $this->usuarioDeTipo($tipo)->id;
        DB::table('users')->where('id', $id)->update(['is_superuser' => 0]);
        DB::table('role_user')->where('user_id', $id)->delete();

        return $id;
    }

    private function rol(string $nombre): int
    {
        return (int) DB::table('roles')->where('name', $nombre)->whereNull('deleted_at')->value('id');
    }

    /** @return list<array{string}> */
    public static function tipos(): array
    {
        return [['Profesor'], ['Alumno'], ['Acudiente']];
    }

    #[DataProvider('tipos')]
    public function test_sin_fila_en_role_user_tiene_el_rol_de_su_tipo_en_los_tres_sitios(string $tipo): void
    {
        $id = $this->sinRoles($tipo);

        $this->assertSame([$tipo], array_column(Role::getUserRoles($id), 'name'));
        $this->assertSame([$tipo], array_column((new ContextoDeUsuario)->para(User::find($id))->roles, 'name'));
        $this->assertSame([$tipo], User::find($id)->rolesConLosImplicitos()->pluck('name')->all());
        $this->assertSame(0, DB::table('role_user')->where('user_id', $id)->count(), 'No se escribe en role_user.');
    }

    public function test_los_asignados_se_suman_y_el_del_tipo_no_sale_dos_veces(): void
    {
        $id = $this->sinRoles('Profesor');
        DB::table('role_user')->insert([
            ['user_id' => $id, 'role_id' => $this->rol('Profesor')],
            ['user_id' => $id, 'role_id' => $this->rol('Rector')],
        ]);

        $esperado = ['Profesor', 'Rector'];
        $this->assertEqualsCanonicalizing($esperado, array_column(Role::getUserRoles($id), 'name'));
        $contexto = (new ContextoDeUsuario)->para(User::find($id));
        $this->assertEqualsCanonicalizing($esperado, array_column($contexto->roles, 'name'));
        $this->assertEqualsCanonicalizing($esperado, User::find($id)->rolesConLosImplicitos()->pluck('name')->all());
    }

    public function test_el_rol_del_tipo_trae_sus_permisos(): void
    {
        $id = $this->sinRoles('Profesor');
        $esperados = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $this->rol('Profesor'))->pluck('permissions.name')->all();
        $this->assertNotSame([], $esperados, 'El seed no da ningún permiso al rol Profesor: la prueba no mediría nada.');

        $this->assertEqualsCanonicalizing($esperados, (new ContextoDeUsuario)->para(User::find($id))->perms);
    }

    public function test_un_administrativo_no_recibe_rol_implicito(): void
    {
        $id = (int) DB::table('users')->insertGetId([
            'username' => 'sin_rol_'.uniqid(), 'password' => 'x', 'tipo' => 'Usuario', 'is_superuser' => 0,
        ]);

        $this->assertSame([], Role::rolDelTipo($id));
    }
}
