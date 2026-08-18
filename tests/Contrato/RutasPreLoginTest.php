<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\Route;

/**
 * Las rutas que el frontend llama SIN sesión no pueden exigir token.
 *
 * La lista la levantó la sesión de `myvc_front` (18 ago 2026) recorriendo los
 * cuatro estados que viven fuera del área autenticada —`main`, `login`,
 * `reset-password` y `logout`— y `AuthService`. Todo lo demás cuelga de `panel`,
 * que sí resuelve la sesión.
 *
 * **Esto es lo que la auditoría del backend no puede saber leyéndose a sí
 * mismo.** Dos de estas rutas escriben datos de una persona y parecen "de
 * usuario", pero por definición se ejecutan sin sesión:
 *
 *   - `publicaciones/ultimas` pinta las noticias dentro de la propia pantalla
 *     de login.
 *   - `login/reset-password` se llama desde el enlace del correo: el usuario no
 *     ha iniciado sesión —no puede, ha olvidado la contraseña— y el token del
 *     reseteo viaja en la URL, no en la cabecera.
 *
 * Si alguna acaba con guard, no se rompe una función suelta: se rompe la entrada
 * al sistema, y el usuario no tiene forma de salir de ahí.
 */
class RutasPreLoginTest extends CasoDeContrato
{
    /**
     * Verbo y URI tal y como las llama el frontend.
     *
     * `publicaciones/ultimas` va por PUT aunque solo lea. No es un error de esta
     * lista: es como la llama el front.
     */
    private const PRE_LOGIN = [
        ['PUT',  'login/crear-prematricula'],
        ['PUT',  'publicaciones/ultimas'],
        ['POST', 'login/recuperar-clave'],
        ['POST', 'login/ver-pass'],           // alias, mientras el front migra
        ['PUT',  'login/reset-password'],
        ['POST', 'login'],
        ['POST', 'login/credentials'],
        ['PUT',  'login/logout'],
    ];

    public function test_ninguna_lleva_guard_de_autenticacion(): void
    {
        $conGuard = [];

        foreach (self::PRE_LOGIN as [$verbo, $uri]) {
            $ruta = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === 'api/' . $uri && in_array($verbo, $r->methods(), true)
            );

            $this->assertNotNull($ruta, "La ruta {$verbo} api/{$uri} no existe. ¿Se renombró?");

            if (in_array('auth.token', $ruta->middleware(), true)) {
                $conGuard[] = $verbo . ' api/' . $uri;
            }
        }

        $this->assertSame([], $conGuard,
            "Estas rutas las llama el frontend SIN sesión y ahora exigen token.\n" .
            "Protegerlas rompe la entrada al sistema:\n  " . implode("\n  ", $conGuard));
    }

    public function test_ninguna_responde_401_sin_token(): void
    {
        $rotas = [];

        foreach (self::PRE_LOGIN as [$verbo, $uri]) {
            $r = $this->json($verbo, '/api/' . $uri);

            // Cualquier código vale menos 401: sin parámetros unas darán 422 y
            // otras 500. Lo que no puede pasar es que rechacen por falta de token.
            if ($r->status() === 401) {
                $rotas[] = $verbo . ' api/' . $uri;
            }
        }

        $this->assertSame([], $rotas,
            "Responden 401 sin token, y el frontend las llama sin sesión:\n  " .
            implode("\n  ", $rotas));
    }

    /**
     * Cerrar sesión con el token ya caducado tiene que funcionar, y funcionar
     * dos veces seguidas.
     *
     * Devolvía 500 SIEMPRE: `DB::update()` devuelve un entero y el código le
     * aplicaba `[0]`. Estaba así desde 2021 y pasó desapercibido porque hasta
     * PHP 7.3 indexar un entero devolvía null en silencio.
     */
    public function test_logout_funciona_y_es_idempotente(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        $this->putJson('/api/login/logout', ['user_id' => $usuario->id])->assertStatus(200);
        $this->putJson('/api/login/logout', ['user_id' => $usuario->id])->assertStatus(200);

        // Sin user_id tampoco puede reventar: el front puede no tenerlo si perdió
        // el estado.
        $this->putJson('/api/login/logout', [])->assertStatus(200);
    }
}
