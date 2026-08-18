<?php

namespace Tests\Contrato;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/**
 * Las rutas con guard rechazan a quien no presenta token.
 *
 * La auditoría (docs/migracion/04-auditoria-autenticacion.md) encontró 58 rutas
 * que escribían en la base sin resolver al usuario. Se cerraron con el
 * middleware `auth.token`. Esto comprueba que siguen cerradas.
 *
 * La lista NO está escrita a mano: sale de la tabla de rutas. Si alguien quita
 * un `->middleware('auth.token')`, la ruta desaparece de este test en vez de
 * fallar — por eso hay además una comprobación del total.
 */
class AutenticacionTest extends CasoDeContrato
{
    /**
     * Cuántas rutas debe haber con guard. Si cambia a propósito, actualiza el
     * número y regenera la auditoría.
     *
     * 58 que escribían + 35 de solo lectura. Las 2 de `publicaciones/ultimas`
     * se quedan fuera a propósito: las llama la pantalla de login. Ver
     * tests/Contrato/RutasPreLoginTest.php.
     */
    private const RUTAS_CON_GUARD = 93;

    /** @return array<int, array{0: string, 1: string}> */
    private function rutasConGuard(): array
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('auth.token', $ruta->middleware(), true)) {
                continue;
            }

            foreach ($ruta->methods() as $verbo) {
                if ($verbo === 'HEAD') {
                    continue;
                }

                // Los {parametros} se rellenan con un valor cualquiera: el guard
                // corre antes que el controlador, así que da igual cuál.
                $uri = preg_replace('/\{[^}]+\}/', '1', $ruta->uri());

                $rutas[] = [$verbo, '/' . $uri];
            }
        }

        return $rutas;
    }

    public function test_no_se_ha_quitado_el_guard_de_ninguna_ruta(): void
    {
        $this->assertCount(
            self::RUTAS_CON_GUARD,
            $this->rutasConGuard(),
            "Cambió el número de rutas con 'auth.token'.\n" .
            'Si es a propósito, actualiza RUTAS_CON_GUARD y regenera la auditoría con ' .
            'tools/auditar-autenticacion.php'
        );
    }

    public function test_sin_token_todas_responden_401(): void
    {
        // La API lleva un limitador global de 60 peticiones por minuto
        // (`throttle:api`). Este test recorre las 93 rutas de una tacada, así que
        // a partir de la 60 recibiría 429 en vez de 401 y estaríamos comprobando
        // el limitador, no el guard.
        $this->withoutMiddleware(ThrottleRequests::class);

        $fallos = [];

        foreach ($this->rutasConGuard() as [$verbo, $uri]) {
            // getStatusCode() y no status(): varias de estas rutas devuelven un
            // fichero (BinaryFileResponse, los exportadores a Excel), y ese tipo
            // de respuesta no tiene status().
            $r = $this->json($verbo, $uri);

            if ($r->getStatusCode() !== 401) {
                $fallos[] = sprintf('%-7s %-52s devolvió %d', $verbo, $uri, $r->getStatusCode());
            }
        }

        $this->assertSame([], $fallos,
            "Estas rutas deberían rechazar con 401 a quien no presenta token:\n" .
            implode("\n", $fallos));
    }

    public function test_con_token_valido_el_guard_deja_pasar(): void
    {
        // La API lleva un limitador global de 60 peticiones por minuto
        // (`throttle:api`). Este test recorre las 93 rutas de una tacada, así que
        // a partir de la 60 recibiría 429 en vez de 401 y estaríamos comprobando
        // el limitador, no el guard.
        $this->withoutMiddleware(ThrottleRequests::class);

        $usuario = $this->usuarioDeTipo('Usuario');
        $token   = $this->tokenDe($usuario->username);

        $rechazadas = [];

        foreach ($this->rutasConGuard() as [$verbo, $uri]) {
            $r = $this->json($verbo, $uri, [], ['Authorization' => 'Bearer ' . $token]);

            // Con token válido puede pasar cualquier cosa —faltan parámetros, el
            // id 1 no existe, el usuario no tiene permiso— menos un 401. Un 401
            // aquí significaría que el guard rechaza a un usuario legítimo.
            if ($r->getStatusCode() === 401) {
                $rechazadas[] = sprintf('%-7s %s', $verbo, $uri);
            }
        }

        $this->assertSame([], $rechazadas,
            "El guard rechaza a un usuario con token válido en:\n" . implode("\n", $rechazadas));
    }
}
