<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ningún constructor de controlador puede resolver al usuario.
 *
 * Laravel instancia el controlador para leerle el middleware, así que un
 * constructor que llame a `User::fromToken()` —que aborta con 401 sin token—
 * revienta `php artisan route:list` e impide `route:cache`. Durante años fue
 * así: 24 controladores lo hacían y no se podía ni listar las rutas.
 *
 * Ahora `$this->user` lo resuelve la primera lectura, con el trait
 * App\Http\Controllers\Concerns\ResuelveElUsuario. Estas dos pruebas impiden que
 * el patrón vuelva a colarse.
 *
 * No arrancan Laravel: leen el código fuente.
 */
class UsuarioPerezosoTest extends TestCase
{
    private const CONTROLADORES = __DIR__.'/../../app/Http/Controllers';

    public function test_ningun_constructor_resuelve_al_usuario(): void
    {
        $culpables = [];

        foreach ($this->fuentes() as [$relativo, $fuente]) {
            $cuerpo = $this->cuerpoDelConstructor($fuente);

            if ($cuerpo !== null && preg_match('/fromToken|JWTAuth|\$this->user\b/', $cuerpo)) {
                $culpables[] = $relativo;
            }
        }

        $this->assertSame([], $culpables, implode("\n", array_merge(
            ['Estos constructores resuelven al usuario, y eso rompe route:list y route:cache:'],
            array_map(fn ($c) => "  - $c", $culpables),
            ['', 'Usa el trait ResuelveElUsuario y lee $this->user donde haga falta.']
        )));
    }

    /**
     * `__get` solo se llama cuando la propiedad NO existe. Si alguien declara
     * `public $user;` en una clase que usa el trait, el trait deja de tener
     * efecto **en silencio**: `$this->user` pasa a valer null y el controlador
     * responde 500 con "Attempt to read property on null", lejos de la causa.
     */
    public function test_ninguna_clase_con_el_trait_declara_user(): void
    {
        $culpables = [];

        foreach ($this->fuentes() as [$relativo, $fuente]) {
            if (! str_contains($fuente, 'use ResuelveElUsuario;')) {
                continue;
            }

            if (preg_match('/^\s*(public|protected|private|var)\s+\$user\s*[;=]/m', $fuente)) {
                $culpables[] = $relativo;
            }
        }

        $this->assertSame([], $culpables, implode("\n", array_merge(
            ['Estas clases usan ResuelveElUsuario y además declaran $user, que lo anula:'],
            array_map(fn ($c) => "  - $c", $culpables),
            ['', 'Quita la declaración: el trait resuelve $this->user por __get.']
        )));
    }

    /** @return iterable<array{0:string,1:string}> archivo relativo y fuente sin comentarios */
    private function fuentes(): iterable
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::CONTROLADORES)
        );

        foreach ($iterador as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            yield [
                str_replace(self::CONTROLADORES.'/', '', $archivo->getPathname()),
                $this->sinComentarios(file_get_contents($archivo->getPathname())),
            ];
        }
    }

    /** El cuerpo del `__construct`, o null si la clase no tiene. */
    private function cuerpoDelConstructor(string $fuente): ?string
    {
        if (! preg_match('/function\s+__construct\s*\(/', $fuente, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $abre = strpos($fuente, '{', $m[0][1]);

        if ($abre === false) {
            return null;
        }

        $profundidad = 0;
        $largo = strlen($fuente);

        for ($i = $abre; $i < $largo; $i++) {
            if ($fuente[$i] === '{') {
                $profundidad++;
            } elseif ($fuente[$i] === '}') {
                $profundidad--;

                if ($profundidad === 0) {
                    return substr($fuente, $abre, $i - $abre + 1);
                }
            }
        }

        return null;
    }

    /**
     * Sin comentarios, con el tokenizador y no con expresiones regulares: hay
     * constructores con código viejo comentado dentro que daría falso positivo.
     */
    private function sinComentarios(string $fuente): string
    {
        $limpio = '';

        foreach (token_get_all($fuente) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $limpio .= $token[1];

                continue;
            }
            $limpio .= $token;
        }

        return $limpio;
    }
}
