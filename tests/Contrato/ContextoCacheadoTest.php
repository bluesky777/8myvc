<?php

namespace Tests\Contrato;

use App\Services\ContextoDeUsuario;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La caché del contexto de usuario — el paso 9 del plan de rendimiento.
 *
 * Va **apagada** (`CONTEXTO_SEGUNDOS=0`), y lo primero que se comprueba aquí es
 * eso, porque es la decisión: medida con el driver `file` en el docker, la
 * caché ahorra 0,75 ms y tres consultas por petición sobre una petición que
 * cuesta decenas de milisegundos. Es de la misma familia que `route:cache`, que
 * el plan ya marcó como ruido. El código se queda —probado y con su interruptor—
 * para el colegio cuyo registro de consultas lentas diga que allí sí paga.
 *
 * Lo demás que se fija aquí es lo que la haría insegura si alguien la enciende:
 * que un rol retirado deje de valer en el acto, y que la clave lleve dentro el
 * nombre de la base.
 */
class ContextoCacheadoTest extends CasoDeContrato
{
    public function test_viene_apagada(): void
    {
        $this->assertSame(0, (int) config('rendimiento.contexto.segundos'),
            'La caché del contexto no puede venir encendida por defecto: es un techo de obsolescencia '.
            'sobre la configuración del colegio, y la medición dice que lo que ahorra es ruido.');
    }

    public function test_apagada_cada_peticion_vuelve_a_montar_el_contexto(): void
    {
        config(['rendimiento.contexto.segundos' => 0]);

        $usuario = $this->usuarioDeTipo('Profesor');

        $this->assertGreaterThan(0, $this->consultasAlResolver($usuario),
            'Apagada, el contexto tiene que salir de la base cada vez.');
    }

    public function test_encendida_la_segunda_no_toca_la_base(): void
    {
        config(['rendimiento.contexto.segundos' => 300]);
        Cache::flush();

        $usuario = $this->usuarioDeTipo('Profesor');

        $primera = $this->consultasAlResolver($usuario);
        $segunda = $this->consultasAlResolver($usuario);

        $this->assertGreaterThan(0, $primera, 'La primera resolución sí monta el contexto.');
        $this->assertSame(0, $segunda,
            "La segunda resolución hizo {$segunda} consultas. Con la caché encendida deberían ser cero.");
    }

    /**
     * La clave lleva el nombre de la base dentro.
     *
     * Hoy cada colegio tiene su `storage/` propio, así que no hay colisión
     * posible; el día que deje de tenerlo —o que se pase a un Redis compartido—
     * una clave `usuario.contexto.5` le serviría al usuario 5 de un colegio el
     * contexto del 5 de otro: nombre, grupo, notas y permisos ajenos. Cuesta
     * nada dejarlo cerrado de antemano.
     */
    public function test_la_clave_no_puede_chocar_entre_colegios(): void
    {
        config(['rendimiento.contexto.segundos' => 300]);
        Cache::flush();

        $usuario = $this->usuarioDeTipo('Profesor');
        app(ContextoDeUsuario::class)->para(User::find($usuario->id));

        $esperada = 'usuario.contexto.'.DB::connection()->getDatabaseName().'.'.$usuario->id.'.'.$usuario->periodo_id;

        $this->assertTrue(Cache::has($esperada),
            "El contexto no quedó guardado en '{$esperada}'. Si la clave cambió, comprueba que sigue "
            .'llevando dentro la base: es lo que impide que dos colegios se pisen.');
    }

    /**
     * Un rol retirado deja de valer en el acto.
     *
     * Los permisos viajan dentro del contexto y `RolesController` decide con
     * ellos (`in_array('can_edit_usuarios', $user->perms)`). Cachear sin borrar
     * al cambiar el rol es dejar vivo un permiso que alguien acaba de quitar,
     * y eso ya no es rendimiento.
     */
    public function test_quitarle_un_rol_le_borra_el_contexto(): void
    {
        config(['rendimiento.contexto.segundos' => 300]);
        Cache::flush();

        $usuario = $this->usuarioDeTipo('Profesor');
        $manager = DB::table('roles')->where('name', 'Manager')->value('id');

        DB::table('role_user')->insert(['user_id' => $usuario->id, 'role_id' => $manager]);

        $conRol = app(ContextoDeUsuario::class)->para(User::find($usuario->id));
        $this->assertContains('can_edit_usuarios', $conRol->perms, 'El rol Manager debería traer ese permiso.');

        // Lo que hace RolesController::putRemoveroletouser, en el mismo orden.
        DB::table('role_user')->where('user_id', $usuario->id)->where('role_id', $manager)->delete();
        ContextoDeUsuario::olvidar(User::find($usuario->id));

        $sinRol = app(ContextoDeUsuario::class)->para(User::find($usuario->id));

        $this->assertNotContains('can_edit_usuarios', $sinRol->perms,
            'El permiso siguió vivo después de quitarle el rol: la caché no se borró.');
    }

    /** Consultas que cuesta resolver el contexto, sin contar el `User::find`. */
    private function consultasAlResolver($usuario): int
    {
        $modelo = User::find($usuario->id);

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        app(ContextoDeUsuario::class)->para($modelo);

        return $consultas;
    }
}
