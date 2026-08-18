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
            if ($r->getStatusCode() === 401) {
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
        $token   = $this->tokenDe($usuario->username);
        $cab     = ['Authorization' => 'Bearer ' . $token];

        $this->putJson('/api/login/logout', [], $cab)->assertStatus(200);
        $this->putJson('/api/login/logout', [], $cab)->assertStatus(200);

        // Sin token tampoco puede reventar: el front tiene que poder limpiar su
        // estado aunque no le quede nada que presentar.
        $this->putJson('/api/login/logout', [])->assertStatus(200);
    }

    /** El cierre de sesión se registra en el historial del usuario del token. */
    public function test_logout_registra_la_salida(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');
        $token   = $this->tokenDe($usuario->username);

        // El login que acaba de hacer tokenDe() deja la fila abierta.
        $fila = \DB::table('historiales')->where('user_id', $usuario->id)
            ->orderByDesc('id')->first();

        $this->assertNotNull($fila, 'El login debería haber dejado una fila en historiales.');
        $this->assertNull($fila->logout_at);

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200);

        $this->assertNotNull(
            \DB::table('historiales')->where('id', $fila->id)->value('logout_at'),
            'El logout no registró la salida.'
        );
    }

    /**
     * No se puede falsificar el cierre de sesión de otro.
     *
     * El `user_id` llegaba en el cuerpo, así que cualquiera podía marcar la
     * salida de cualquiera sabiendo su id. No lo echaba del sistema, pero
     * corrompía el historial de accesos — justo lo que se mira para reconstruir
     * qué pasó. Ahora el usuario sale del token y el cuerpo se ignora.
     */
    public function test_no_se_puede_cerrar_la_sesion_de_otro(): void
    {
        $mio   = $this->usuarioDeTipo('Usuario');
        $otro  = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($mio->username);

        $suya = \DB::table('historiales')->insertGetId([
            'user_id'    => $otro->id,
            'tipo'       => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Con MI token, pidiendo cerrar la SUYA.
        $this->putJson('/api/login/logout', ['user_id' => $otro->id],
            ['Authorization' => 'Bearer ' . $token])->assertStatus(200);

        $this->assertNull(
            \DB::table('historiales')->where('id', $suya)->value('logout_at'),
            'Se pudo marcar la salida de otro usuario mandando su id en el cuerpo.'
        );
    }

    /**
     * El caso que motiva todo el diseño: cerrar sesión con el token YA CADUCADO.
     *
     * Es lo normal — quien vuelve al día siguiente y pulsa "salir". Por eso el
     * usuario no se resuelve con `User::fromToken()`, que aborta con 401 al
     * expirar, sino decodificando el token: se comprueba la firma, no la fecha.
     */
    public function test_registra_la_salida_con_el_token_caducado(): void
    {
        $usuario = $this->usuarioDeTipo('Usuario');

        // Token legítimamente FIRMADO pero vencido ayer.
        //
        // No sirve `JWTAuth::claims(['exp' => ...])->fromUser()`: tymon valida
        // los claims al CONSTRUIR el token y lanza TokenExpiredException antes de
        // devolverlo. Hay que firmar directamente por el proveedor, que es
        // además el mismo camino que usa el controlador para leerlo.
        $caducado = \JWTAuth::manager()->getJWTProvider()->encode([
            'sub' => $usuario->id,
            'iat' => now()->subDays(2)->timestamp,
            'nbf' => now()->subDays(2)->timestamp,
            'exp' => now()->subDay()->timestamp,
            'jti' => 'test-caducado',
        ]);

        $fila = \DB::table('historiales')->insertGetId([
            'user_id'    => $usuario->id,
            'tipo'       => $usuario->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer ' . $caducado])
            ->assertStatus(200);

        $this->assertNotNull(
            \DB::table('historiales')->where('id', $fila)->value('logout_at'),
            'Con el token caducado no se registró la salida, que es el caso normal.'
        );
    }

    /** Un token inventado no vale: se comprueba la firma. */
    public function test_un_token_falsificado_no_registra_nada(): void
    {
        $otro = $this->usuarioDeTipo('Profesor');

        $fila = \DB::table('historiales')->insertGetId([
            'user_id'    => $otro->id,
            'tipo'       => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cabecera con la forma de un JWT pero firmada con otra cosa.
        $falso = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.'
            . base64_encode(json_encode(['sub' => $otro->id, 'exp' => time() + 3600]))
            . '.firmaInventada';

        $this->putJson('/api/login/logout', [], ['Authorization' => 'Bearer ' . $falso])
            ->assertStatus(200);

        $this->assertNull(
            \DB::table('historiales')->where('id', $fila)->value('logout_at'),
            'Un token con firma inválida pudo marcar la salida de alguien.'
        );
    }

    /** Sin token no se registra nada, pero tampoco falla. */
    public function test_sin_token_no_registra_pero_responde_200(): void
    {
        $otro = $this->usuarioDeTipo('Profesor');

        $suya = \DB::table('historiales')->insertGetId([
            'user_id'    => $otro->id,
            'tipo'       => $otro->tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/login/logout', ['user_id' => $otro->id])->assertStatus(200);

        $this->assertNull(
            \DB::table('historiales')->where('id', $suya)->value('logout_at'),
            'Sin token se pudo marcar la salida de un usuario cualquiera.'
        );
    }
}
