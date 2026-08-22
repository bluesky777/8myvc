<?php

namespace App\Support;

use Illuminate\Support\Facades\Request;

/**
 * Qué campos mandó **el cliente**, antes de que ningún `sanar*` los rellenara.
 *
 * Existe por una frase que costó un fallo de verdad (05 §68):
 *
 * > **Un campo que no se manda no es un campo que no cambia: es un campo que se
 * > pisa.**
 *
 * `putUpdate` de profesores y de alumnos escribía `is_active` con
 * `Request::input('is_active', 1)`. Las pantallas de edición **no mandan ese
 * campo** —el interruptor de «Activo» vive en otra ruta, `guardar-valor`—, así
 * que corregirle el teléfono a alguien le devolvía la entrada al sistema.
 *
 * `Request::has()` no sirve para contestar esto, y ése es el motivo de que haya
 * una clase: los `sanarInput*` hacen `Request::merge()` **antes** de que el
 * controlador lea nada, así que a esa altura `has('email2')` es cierto aunque el
 * cliente no lo mandara nunca. Hay que capturar antes del primer `sanar`, y por
 * eso esto se captura y no se pregunta.
 *
 * No vale para el alta: una cuenta que **nace** con `is_active = 1` por defecto
 * está bien, y son cuatro de los seis sitios. El discriminador es `new User`
 * contra `User::find()`.
 */
final class CamposQueVinieron
{
    /** @param  list<string>  $claves */
    private function __construct(private readonly array $claves) {}

    /**
     * Se llama **antes del primer `sanar*`**. Después ya no mide lo que se cree.
     */
    public static function capturar(): self
    {
        return new self(array_keys(Request::all()));
    }

    public function trae(string $campo): bool
    {
        return in_array($campo, $this->claves, true);
    }
}
