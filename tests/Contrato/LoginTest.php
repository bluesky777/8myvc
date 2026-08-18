<?php

namespace Tests\Contrato;

/**
 * P0 — Login.
 *
 * Es el primer test de la migración y el que más duele si se rompe: si el login
 * cambia de forma, ningún usuario entra, y da igual lo bien que funcione el
 * resto.
 *
 * Se cubren los cuatro tipos de usuario (Alumno, Profesor, Acudiente, Usuario)
 * porque `User::fromToken()` resuelve el contexto con un `switch` de cuatro
 * ramas, cada una con su propia consulta y su propio conjunto de columnas. Son
 * cuatro contratos distintos con el frontend, no uno.
 */
class LoginTest extends CasoDeContrato
{
    /** @dataProvider tiposDeUsuario */
    public function test_login_devuelve_token(string $tipo): void
    {
        $usuario = $this->usuarioDeTipo($tipo);

        $r = $this->postJson('/api/login/credentials', [
            'username' => $usuario->username,
            'password' => self::CLAVE,
        ]);

        $r->assertStatus(200);

        // El frontend lee exactamente esta clave. Si cambia de nombre, nadie entra.
        $this->assertArrayHasKey('el_token', $r->json());
        $this->assertIsString($r->json('el_token'));

        // Un JWT son tres partes separadas por puntos.
        $this->assertCount(3, explode('.', $r->json('el_token')));

        $this->compararConInstantanea(
            'login-credentials-' . strtolower($tipo),
            $this->forma($r->json())
        );
    }

    /** @dataProvider tiposDeUsuario */
    public function test_contexto_de_usuario(string $tipo): void
    {
        $usuario = $this->usuarioDeTipo($tipo);
        $token   = $this->tokenDe($usuario->username);

        $r = $this->postJson('/api/login', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $r->assertStatus(200);

        $this->compararConInstantanea(
            'login-contexto-' . strtolower($tipo),
            $this->forma($r->json())
        );
    }

    public function test_credenciales_malas_no_dan_token(): void
    {
        $usuario = $this->usuarioDeTipo('Alumno');

        $r = $this->postJson('/api/login/credentials', [
            'username' => $usuario->username,
            'password' => 'esta-no-es',
        ]);

        $this->assertNotSame(200, $r->status());
        $this->assertArrayNotHasKey('el_token', (array) $r->json());
    }

    public function test_usuario_inexistente_no_da_token(): void
    {
        $r = $this->postJson('/api/login/credentials', [
            'username' => 'no-existe-este-usuario',
            'password' => self::CLAVE,
        ]);

        $this->assertNotSame(200, $r->status());
        $this->assertArrayNotHasKey('el_token', (array) $r->json());
    }

    public function tiposDeUsuario(): array
    {
        return [
            'alumno'    => ['Alumno'],
            'profesor'  => ['Profesor'],
            'acudiente' => ['Acudiente'],
            'usuario'   => ['Usuario'],
        ];
    }
}
