<?php

namespace Tests\Contrato;

/**
 * P0 — Recuperación de contraseña.
 *
 * Dos cosas que fijar aquí:
 *
 * 1. `login/ver-pass` sigue funcionando como alias de `login/recuperar-clave`.
 *    El frontend se publica por colegio, así que durante un tiempo convivirán
 *    versiones que llaman a una ruta y a la otra. El día que se borre el alias,
 *    este test lo dirá.
 *
 * 2. La respuesta es la misma exista o no el correo. Antes devolvía 'No existe',
 *    y con eso cualquiera podía averiguar qué correos están registrados en el
 *    colegio probándolos uno a uno.
 */
class RecuperarClaveTest extends CasoDeContrato
{
    /** @dataProvider rutas */
    public function test_no_revela_si_el_correo_esta_registrado(string $ruta): void
    {
        $usuario = \DB::table('users')
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($usuario, 'El seed no tiene ningún usuario activo con correo.');

        // 'ruta' es la base del enlace del correo, y el endpoint exige que su
        // host coincida con el de la petición — si no, aborta con 422 antes de
        // mirar el correo, y entonces este test no compararía nada útil.
        $base = ['ruta' => 'http://localhost/'];

        $registrado = $this->postJson($ruta, $base + ['email' => $usuario->email]);
        $inventado  = $this->postJson($ruta, $base + ['email' => 'no-existe-nadie-asi@ejemplo.test']);

        $this->assertSame(
            $registrado->status(),
            $inventado->status(),
            'El código de estado delata si el correo está registrado.'
        );

        $this->assertSame(
            $registrado->getContent(),
            $inventado->getContent(),
            "La respuesta delata si el correo está registrado.\n" .
            "registrado: {$registrado->getContent()}\n" .
            "inventado:  {$inventado->getContent()}"
        );
    }

    /** @dataProvider rutas */
    public function test_rechaza_un_correo_mal_formado(string $ruta): void
    {
        $this->postJson($ruta, [
            'ruta'  => 'http://localhost/',
            'email' => 'esto-no-es-un-correo',
        ])->assertStatus(422);
    }

    /**
     * La base del enlace no puede apuntar fuera del dominio.
     *
     * Sin esto, un atacante pedía el reseteo de la víctima con 'ruta' a su
     * propio sitio: el correo salía legítimo, desde este dominio, con el token
     * dentro de una URL que apuntaba a él. Lo cerró el PR #3; esto lo fija.
     */
    public function test_no_acepta_una_ruta_de_retorno_de_otro_dominio(): void
    {
        $this->postJson('/api/login/recuperar-clave', [
            'ruta'  => 'https://sitio-del-atacante.test/',
            'email' => 'quien-sea@ejemplo.test',
        ])->assertStatus(422);
    }

    /**
     * El alias existe. Cuando el front de todos los colegios use la ruta nueva,
     * se borra la ruta vieja y este test se cae: es el recordatorio.
     */
    public function test_la_ruta_vieja_sigue_siendo_un_alias_de_la_nueva(): void
    {
        $rutas = collect(\Route::getRoutes())->filter(
            fn ($r) => in_array($r->uri(), ['api/login/ver-pass', 'api/login/recuperar-clave'], true)
        );

        $this->assertCount(2, $rutas, 'Deben existir las dos rutas mientras el front migra.');

        $this->assertCount(
            1,
            $rutas->map(fn ($r) => $r->getActionName())->unique(),
            'Las dos rutas deben apuntar al mismo método.'
        );
    }

    public function rutas(): array
    {
        return [
            'ruta nueva' => ['/api/login/recuperar-clave'],
            'alias viejo' => ['/api/login/ver-pass'],
        ];
    }
}
