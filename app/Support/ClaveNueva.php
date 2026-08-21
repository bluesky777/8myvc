<?php

namespace App\Support;

use Illuminate\Support\Facades\Request;

/**
 * La contraseña que se va a escribir, exigida.
 *
 * Existe por lo mismo que Autoriza: el criterio estaba **ausente** en los cuatro
 * sitios que escriben una contraseña, y cada uno fallaba de la misma manera.
 * `Hash::make(null)` y `Hash::make('')` no fallan: devuelven el hash de la cadena
 * vacía, y `login/credentials` con la contraseña vacía responde 200. O sea que un
 * campo que no llega deja la cuenta abierta a cualquiera que sepa el nombre de
 * usuario, sin un solo error por el camino.
 *
 * Los cuatro sitios, y lo que dejaba cada uno (medido el 20 ago 2026):
 *
 *   cambiar-usuarios/poner-password-todos-alumnos      los 1.280 alumnos
 *   cambiar-usuarios/poner-password-todos-acudientes   los 999 acudientes
 *   perfiles/reset-password/{id}                       la persona que se elija
 *   perfiles/cambiarpassword/{id}                      la cuenta de quien la pide
 *
 * **Solo exige que venga y que no esté vacía.** Cuánto debe medir o qué forma
 * debe tener es una política del colegio y no se inventa aquí — el front pide
 * cuatro caracteres en su propia pantalla, y esa cifra es suya.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md §26 y §29.
 */
class ClaveNueva
{
    public static function exigir(string $campo = 'password'): string
    {
        $clave = Request::input($campo);

        if (! is_string($clave) || $clave === '') {
            abort(422, 'Falta la contraseña nueva.');
        }

        return $clave;
    }
}
