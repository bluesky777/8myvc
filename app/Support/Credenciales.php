<?php

namespace App\Support;

use App\User;
use Illuminate\Support\Facades\Hash;

/**
 * Comprobar usuario y contraseña sin pasar por un guard.
 *
 * Existe porque el guard `api` dejó de ser el de JWT y pasó a ser `sesion`
 * (ver config/auth.php), que resuelve al usuario a partir del token de la
 * petición. Un guard así no tiene `attempt()`: no hay dónde meter unas
 * credenciales, porque no es lo que hace.
 *
 * Los cuatro sitios que llamaban a `Auth::attempt()` son los lectores de
 * Tardanzas, que mandan usuario y contraseña en el cuerpo de CADA petición y no
 * usan token para nada. Lo que necesitan no es un guard, es esto.
 *
 * Hace lo mismo que hacía `EloquentUserProvider`: busca por `username` y
 * compara el hash. **Tampoco filtra `deleted_at`**, igual que antes — un
 * usuario borrado puede seguir entrando por Tardanzas. Se deja como estaba a
 * propósito: cambiarlo aquí, sin tests y en el camino de unos aparatos físicos
 * que están montados en los colegios, es meter un cambio de comportamiento
 * donde solo tocaba quitar el guard. Anotado en
 * docs/migracion/04-auditoria-autenticacion.md.
 */
class Credenciales
{
    public static function verificar($username, $password): ?User
    {
        if (! is_string($username) || $username === '') {
            return null;
        }

        $usuario = User::where('username', '=', $username)->first();

        if ($usuario === null) {
            return null;
        }

        return Hash::check((string) $password, $usuario->getAuthPassword()) ? $usuario : null;
    }
}
