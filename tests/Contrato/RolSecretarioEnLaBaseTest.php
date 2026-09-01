<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El rol `Secretario` está **en la base de tests**, y no sólo en la migración.
 *
 * Decisión de Joseth del 31 ago 2026. `create_rol_secretario` inserta el rol, pero
 * `database/dumps/test-seed.sql` hace `TRUNCATE TABLE roles` y las migraciones
 * corren ANTES del seed en `construir-bd-test.sh`, así que la base de tests se
 * quedaba con **once** roles y sin él. Ahora son **doce**.
 *
 * ## Qué comprueba esto que `SecretarioTest` no comprueba
 *
 * `SecretarioTest` ya ejercita la rama `Secretario` de `Autoriza::esAdministrativo()`
 * en las dos direcciones, y lo hace bien — pero **se fabrica el rol dentro de su
 * transacción** (`idDelRol()` lo inserta si falta). Por eso pasa igual con once
 * roles que con doce: *no puede ver esta diferencia*, que es exactamente la razón
 * por la que el agujero sobrevivió a tener un test al lado.
 *
 * Aquí **no se crea nada**: si el rol no está en el seed, este test se pone rojo.
 *
 * ## Y por qué hace falta un guardián y no basta con la fila
 *
 * **`test-seed.sql` se GENERA** (`tools/generar-seed-test.php`) desde una base real,
 * que tiene once roles porque el doce lo pone una migración. O sea que quien
 * regenere el volcado **volverá a dejar once y la fila desaparece sin que falle
 * nada** — salvo por este test. Es el mismo modo de fallo que el propio agujero
 * que viene a tapar, un turno más tarde.
 *
 * > Por eso los dos rodeos a mano que ya existen —`ConsecutivoDeCertificadosTest`
 * > y `BoletinIndependientePeriodoTest`— **se quedan**: fabricarse el rol es lo que
 * > los hace inmunes a una regeneración del seed. Quitarlos ahora los dejaría
 * > colgando de una fila que un `generar-seed-test.php` se lleva sin avisar.
 */
class RolSecretarioEnLaBaseTest extends CasoDeContrato
{
    /**
     * La población, delante: doce roles y uno de ellos `Secretario`.
     *
     * Se afirma el número **y** el nombre a propósito. Sólo el nombre no distingue
     * «el seed lo trae» de «una migración lo metió y el truncado no llegó a
     * correr»; sólo el número no dice cuál falta.
     */
    public function test_el_seed_trae_los_doce_roles_con_el_secretario_dentro(): void
    {
        $roles = DB::select('SELECT name FROM roles ORDER BY id');
        $nombres = array_map(static fn ($r) => $r->name, $roles);

        $this->assertContains('Secretario', $nombres,
            "La base de tests no tiene el rol `Secretario`.\n"
            .'Lo inserta `create_rol_secretario`, pero `test-seed.sql` hace `TRUNCATE TABLE '
            ."roles` DESPUÉS —las migraciones corren antes del seed—.\n"
            .'Si acabas de regenerar el volcado con `tools/generar-seed-test.php`, la fila se '
            ."ha ido con él y hay que volver a añadirla a mano.\n"
            .'Roles que hay ('.count($nombres).'): '.implode(', ', $nombres));

        $this->assertCount(12, $roles,
            'La base de tests tiene '.count($roles).' roles y deberían ser doce. '
            .'Hay ('.implode(', ', $nombres).').');
    }

    /**
     * Y el rol del seed **sirve para lo que se creó**: abre `esAdministrativo()`.
     *
     * El sujeto se monta con `usuarioLlanoDelPersonal()` y el rol se le pone **a esa
     * misma fila**, así que entre el 403 y el 200 **lo único que cambia es
     * `role_user`**. Con dos personas distintas esto demostraría que dos personas se
     * comportan distinto, que no es lo que dice el nombre del test.
     *
     * Las dos mitades en la misma corrida, que es lo único que impide que pase por
     * otra razón — un 200 a secas no distingue «el rol funciona» de «esta ruta no
     * pedía nada».
     */
    public function test_el_rol_del_seed_abre_lo_que_is_superuser_abria_solo(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();

        $rol = DB::table('roles')->where('name', 'Secretario')->first();

        $this->assertNotNull($rol,
            'Sin el rol en la base este test no puede decir nada; ver el test de arriba.');

        // Sin el rol: la puerta está cerrada. Si esto ya diera 200, el 200 de abajo no
        // demostraría nada.
        $this->withToken($this->tokenDe($usuario->username))
            ->postJson('/api/acudientes/crear', $this->acudienteNuevo())
            ->assertStatus(403);

        // Lo único que cambia entre las dos mitades.
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => $rol->id,
        ]);

        $this->withToken($this->tokenDe($usuario->username))
            ->postJson('/api/acudientes/crear', $this->acudienteNuevo())
            ->assertStatus(200);
    }

    /**
     * Los datos mínimos de un acudiente.
     *
     * `tipo_doc` y `parentesco` viajan como objetos y no como valores —el controlador
     * hace `Request::input('tipo_doc')['id']`—, que es la forma que manda el front y
     * no una elección de este test. Igual que en `SecretarioTest`.
     *
     * @return array<string, mixed>
     */
    private function acudienteNuevo(): array
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($alumno, 'El seed no tiene alumnos: un acudiente necesita a quién acudir.');

        return [
            'nombres' => 'Secretaría',
            'apellidos' => 'Del Seed',
            'sexo' => 'F',
            'documento' => '901'.random_int(100000, 999999),
            'tipo_doc' => ['id' => 1],
            'alumno_id' => $alumno->id,
            'parentesco' => ['parentesco' => 'Madre'],
        ];
    }
}
