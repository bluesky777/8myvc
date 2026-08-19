<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Cuántas contraseñas se pueden probar por minuto.
 *
 * Antes, las mismas que cualquier otra petición: 60. Son 86.400 intentos al día
 * por IP contra 2.318 cuentas con contraseñas de colegio, y al menos las de
 * prematrícula nacen con una conocida. Eso no resiste un diccionario.
 *
 * Los cubos son dos y tapan ataques distintos: por IP frena a quien prueba
 * muchas contraseñas de una cuenta; por usuario frena a quien reparte los
 * intentos entre muchas IP contra la misma cuenta.
 */
class LimiteDeIntentosTest extends CasoDeContrato
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ip:127.0.0.1');
    }

    private function intentar(string $username, string $clave = 'no-es-la-clave')
    {
        return $this->postJson('/api/login/credentials', [
            'username' => $username,
            'password' => $clave,
        ]);
    }

    public function test_al_sexto_intento_fallido_desde_la_misma_ip_responde_429(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario')->username;

        for ($i = 1; $i <= 5; $i++) {
            $this->intentar($usuario)->assertStatus(400);
        }

        $this->intentar($usuario)->assertStatus(429);
    }

    /**
     * Y la contraseña buena tampoco entra una vez agotado el cupo: si el límite
     * se saltara al acertar, bastaría con seguir probando.
     */
    public function test_agotado_el_cupo_ni_la_contrasena_correcta_pasa(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario')->username;

        for ($i = 1; $i <= 5; $i++) {
            $this->intentar($usuario);
        }

        $this->intentar($usuario, self::CLAVE)->assertStatus(429);
    }

    /**
     * El cubo por usuario no puede castigar a un tercero: agotar los intentos
     * de una cuenta desde muchas IP no debe dejar fuera a las demás cuentas de
     * esa oficina. Aquí se comprueba el otro lado — que el cubo por usuario
     * existe y es independiente del de IP.
     */
    public function test_el_cupo_por_usuario_es_independiente_del_de_ip(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario')->username;

        for ($i = 1; $i <= 5; $i++) {
            $this->intentar($usuario);
        }

        // Mismo minuto, IP distinta, misma cuenta: el cubo por usuario ya está lleno.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->intentar($usuario)
            ->assertStatus(429);
    }

    /**
     * El único endpoint público que escribe: sin token crea un alumno, su
     * matrícula y un usuario activo. Con el límite general eran 7.200 filas por
     * hora desde una IP.
     */
    public function test_la_prematricula_publica_tiene_su_propio_limite(): void
    {
        $ruta = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/login/crear-prematricula'
        );

        $this->assertNotNull($ruta, 'Desapareció api/login/crear-prematricula.');
        $this->assertContains('throttle:prematricula', $ruta->middleware());
        $this->assertNotContains('auth.token', array_diff(
            $ruta->middleware(), $ruta->excludedMiddleware()
        ), 'Sigue siendo pública, que es el motivo de que necesite el límite.');
    }

    public function test_las_rutas_que_aceptan_contrasena_llevan_el_limite(): void
    {
        $esperado = [
            'POST api/auth/login',
            'POST api/login',
            'POST api/login/credentials',
            'POST api/login/recuperar-clave',
            'POST api/login/ver-pass',
            'POST api/tardanzas/login',
            'PUT api/login/reset-password',
        ];

        $reales = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('throttle:login', $ruta->middleware(), true)) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
                $reales[] = $verbo.' '.$ruta->uri();
            }
        }

        sort($reales);

        $this->assertSame($esperado, $reales, 'Cambió la lista de rutas con throttle:login.');
    }
}
