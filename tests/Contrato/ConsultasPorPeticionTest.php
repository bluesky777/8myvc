<?php

namespace Tests\Contrato;

use App\Services\ContextoDeUsuario;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cuesta una petición autenticada, contado.
 *
 * `GET api/periodos` devuelve unas pocas filas de una tabla pequeña. Antes del
 * 19 ago 2026 costaba **nueve consultas, y solo una era del endpoint**. Las
 * otras ocho eran resolver quién pregunta, dos veces:
 *
 *   1-2. el token y su usuario                 ← el limitador de peticiones
 *   3.   marcar el token como usado
 *   4-5. el token y su usuario, otra vez       ← el middleware `auth.token`
 *   6.   la consulta de cuarenta columnas del contexto
 *   7.   los roles
 *   8.   los permisos, UNA CONSULTA POR ROL
 *
 * El limitador llama a `$request->user()` solo para decidir la clave del cubo,
 * y eso pasa por el guard; después el middleware vuelve a resolver por su
 * cuenta. Son los pasos 7 y 8 del plan de rendimiento.
 *
 * Este test cuenta. Si alguien vuelve a meter una resolución por petición, el
 * número sube y esto lo dice — que es la única forma de que no vuelva, porque
 * una consulta de más no se nota mirando la pantalla.
 */
class ConsultasPorPeticionTest extends CasoDeContrato
{
    public function test_una_peticion_autenticada_no_resuelve_el_token_dos_veces(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($usuario->username);

        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = preg_replace('/\s+/', ' ', trim($consulta->sql));
        });

        $this->getJson('/api/periodos', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $tokens = array_filter($consultas,
            fn ($sql) => str_contains($sql, 'from `personal_access_tokens`') && str_starts_with($sql, 'select'));

        $this->assertCount(1, $tokens, implode("\n", array_merge(
            ['El token se buscó '.count($tokens).' veces en una sola petición. Consultas:'],
            array_map(fn ($sql) => '  - '.substr($sql, 0, 100), $consultas)
        )));

        $usuarios = array_filter($consultas, fn ($sql) => str_starts_with($sql, 'select * from `users`'));

        $this->assertCount(1, $usuarios,
            'La fila de `users` se leyó '.count($usuarios).' veces. Debería salir de la memoria de la petición.');
    }

    /**
     * Los permisos de todos los roles caben en una consulta.
     *
     * El bucle viejo hacía una por rol. Con `role_user` en 2.346 filas hay
     * usuarios con más de uno, y cada rol extra costaba una consulta en CADA
     * petición que hiciera esa persona.
     */
    public function test_los_permisos_salen_en_una_sola_consulta_haya_los_roles_que_haya(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');

        // `Manager` es el único rol con permisos de verdad —16— y no lo tiene
        // nadie. Se lo damos aquí dentro: la transacción del caso lo deshace.
        $manager = DB::table('roles')->where('name', 'Manager')->value('id');
        DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $manager]);

        $consultas = 0;
        DB::listen(function ($consulta) use (&$consultas) {
            if (str_contains($consulta->sql, 'permission_role')) {
                $consultas++;
            }
        });

        $contexto = app(ContextoDeUsuario::class)->para(User::find($usuario->id));

        $this->assertSame(1, $consultas,
            "Con dos roles hicieron falta {$consultas} consultas de permisos. Debería ser una.");

        $this->assertGreaterThan(1, count($contexto->roles), 'El usuario debería tener dos roles aquí.');

        $this->assertSame($this->permisosComoLosCalculabaElBucle($contexto->roles), $contexto->perms,
            'La lista de permisos cambió al colapsar el N+1, y esa lista está en los snapshots del login.');
    }

    /**
     * El algoritmo viejo, tal cual estaba: una consulta por rol, en el orden en
     * que vienen los roles, conservando los repetidos. Es contra esto que se
     * compara la consulta nueva.
     */
    private function permisosComoLosCalculabaElBucle(array $roles): array
    {
        $perms = [];

        foreach ($roles as $role) {
            $permisos = DB::select('SELECT pm.name from permission_role pmr
                    inner join permissions pm on pm.id = pmr.permission_id
                        and pmr.role_id = :role_id', [':role_id' => $role->id]);

            foreach ($permisos as $permiso) {
                $perms[] = $permiso->name;
            }
        }

        return $perms;
    }
}
